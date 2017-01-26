<?php

namespace PHPPM;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\Server;
use React\Stream\Stream;

class ProcessManager
{
    /**
     * @var Slave[]
     */
    protected $slaves = [];

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var Server
     */
    protected $controller;

    /**
     * @var Server
     */
    protected $web;

    /**
     * @var int
     */
    protected $slavesCount = 1;

    /**
     * Whether the server is up and thus creates new slaves when they die or not.
     *
     * @var bool
     */
    protected $running = false;

    /**
     * @var int
     */
    protected $index = 0;

    /**
     * @var string
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

    /**
     * @var string|null
     */
    protected $appenv;

    /**
     * @var string
     */
    protected $host = '127.0.0.1';

    /**
     * @var int
     */
    protected $port = 8080;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $logFile;

    /**
     * @var int
     */
    protected $workerMemoryLimit;

    /**
     * @var bool
     */
    protected $shutdownLock = false;

    /**
     * @var bool
     */
    protected $allowNewInstances = true;

    /**
     * @var string
     */
    private $workingDirectory;

    /**
     * @var int
     */
    private $requestTimeout;

    /**
     * Create process manager.
     *
     * @param int $port
     * @param string $host
     * @param int $slaveCount
     * @param int $workerMemoryLimit
     * @param string $logFile
     * @param int $requestTimeout
     */
    public function __construct($port = 8080, $host = '127.0.0.1', $slaveCount = 8, $requestTimeout = null, $workerMemoryLimit, $logFile)
    {
        cli_set_process_title('react master');

        $this->slavesCount       = $slaveCount;
        $this->host              = $host;
        $this->port              = $port;
        $this->workerMemoryLimit = $workerMemoryLimit * 1024 * 1024;
        $this->requestTimeout    = $requestTimeout;

        $lineFormatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%', null, false, true);

        $this->logFile = $logFile;
        $this->logger  = new Logger(static::class);
        $this->logger->pushHandler(new StreamHandler($logFile));
        $this->logger->pushHandler((new ErrorLogHandler())->setFormatter($lineFormatter));

        set_error_handler(\Closure::bind(function ($errno, $errstr, $errfile, $errline) {
            $this->logger->crit(sprintf('Fatal error "[%s] %s" in %s:%s', $errno, $errstr, $errfile, $errline));
        }, $this));

        $this->logger->debug(sprintf('Workers: %s', $slaveCount));
        $this->logger->debug(sprintf('Worker memory limit: %s bytes', $this->workerMemoryLimit));
        $this->logger->debug(sprintf('Host: %s:%s', $host, $port));
        $this->logger->debug(sprintf('Timeout: %s seconds', $this->slavePingTimeout()));
    }

    public function fork()
    {
        if ($this->running) {
            throw new \LogicException('Can not fork when already run.');
        }

        if (!pcntl_fork()) {
            $this->run();
        }
    }

    /**
     * @param string $bridge
     */
    public function setBridge($bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * @return string
     */
    public function getBridge()
    {
        return $this->bridge;
    }

    /**
     * @param string $appBootstrap
     */
    public function setAppBootstrap($appBootstrap)
    {
        $this->appBootstrap = $appBootstrap;
    }

    /**
     * @return string
     */
    public function getAppBootstrap()
    {
        return $this->appBootstrap;
    }

    /**
     * @param string|null $appenv
     */
    public function setAppEnv($appenv)
    {
        $this->appenv = $appenv;
    }

    /**
     * @return string
     */
    public function getAppEnv()
    {
        return $this->appenv;
    }

    public function run()
    {
        $this->loop       = Factory::create();
        $this->controller = new Server($this->loop);
        $this->controller->on('connection', [$this, 'onSlaveConnection']);
        $this->controller->listen($this->port, $this->host);

        $http = new \React\Http\Server($this->controller);
        /** @noinspection PhpUnusedParameterInspection */
        $http->on('request', \Closure::bind(function (Request $request, Response $response) {
            $response->writeHead();
            $response->end($this->clusterStatusAsJson());
        }, $this));

        $this->web = new Server($this->loop);
        $this->web->on('connection', [$this, 'onWeb']);
        $this->web->listen($this->port + 1, $this->host);

        $this->runSlaves();

        $this->running = true;

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(1, \Closure::bind(function () {
            if (count($this->activeSlaves()) === 0) {
                $this->logger->crit('Slaves count zero! Run slaves!');
                $this->runSlaves();
            }
        }, $this));

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(1, \Closure::bind(function () {
            foreach ($this->slaves as $idx => $slave) {
                if ($slave->getPingAt() === null) {
                    continue;
                }

                if ((time() - strtotime($slave->getPingAt())) > $this->slavePingTimeout()) {
                    $this->logger->info("Timeout ping from worker at pid {$slave->getPid()}. Killing it ...");
                    if (posix_kill($slave->getPid(), SIGKILL)) {
                        $this->logger->warn("Killed worker at pid {$slave->getPid()}.");
                        $this->removeSlave($idx);
                    } else {
                        $this->logger->warn("Can't kill worker at pid {$slave->getPid()}.");
                    }
                }
            }
        }, $this));

        $this->loop->run();
    }

    /**
     * Calculate slave ping timeout.
     * @return int
     */
    private function slavePingTimeout()
    {
        if ($this->requestTimeout === null) {
            return 30 + ProcessSlave::PING_TIMEOUT * 3;
        }

        return $this->requestTimeout;
    }

    /**
     * @param int $count
     */
    private function runSlaves($count = null)
    {
        $this->allowNewInstances = false;

        if ($count === null) {
            $count = $this->slavesCount;
        }

        if ($count === count($this->slaves)) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->newInstance();
        }

        $this->allowNewInstances = true;
    }

    /**
     * @param Connection $incoming
     */
    public function onWeb(Connection $incoming)
    {
        do {
            $slaves  = array_values($this->activeSlaves());
            $slaveId = $this->getNextSlave();
        } while (!array_key_exists($slaveId, $slaves));

        /** @var Slave $slave */
        $slave    = $slaves[$slaveId];
        $port     = $slave->getPort();
        $client   = stream_socket_client(sprintf('tcp://%s:%s', $this->host, $port));
        $redirect = new Stream($client, $this->loop);

        $incoming->on('close', function () use ($redirect) {
            $redirect->end();
        });

        $incoming->on('error', function () use ($redirect) {
            $redirect->end();
        });

        $incoming->on('data', function ($data) use ($redirect) {
            $redirect->write($data);
        });

        $redirect->on('close', function () use ($incoming) {
            $incoming->end();
        });

        $redirect->on('error', function () use ($incoming) {
            $incoming->end();
        });

        $redirect->on('data', function ($data) use ($incoming) {
            $incoming->write($data);
        });
    }

    /**
     * @return integer
     */
    protected function getNextSlave()
    {
        $count = count($this->activeSlaves());

        $this->index++;
        if ($count >= $this->index) {
            //end
            $this->index = 0;
        }

        return $this->index;
    }

    public function onSlaveConnection(Connection $conn)
    {
        $conn->on('data', \Closure::bind(function ($data) use ($conn) {
            $this->processMessage($data, $conn);
        }, $this));
        $conn->on('close', \Closure::bind(function () use ($conn) {
            foreach ($this->slaves as $idx => $slave) {
                if ($slave->equalsByConnection($conn)) {
                    $this->removeSlave($idx);
                }
            }
        }, $this));
    }

    public function processMessage($data, Connection $conn)
    {
        $data = json_decode($data, true);

        $method = 'command'.ucfirst($data['cmd']);
        if (method_exists($this, $method) && is_callable([$this, $method])) {
            $this->$method($data, $conn);
        }
    }

    private function clusterStatusAsJson()
    {
        $data['pid']  = getmypid();
        $data['host'] = $this->host;
        $data['port'] = $this->port;
        if ($this->shutdownLock) {
            $data['shutdown'] = true;
        }
        $data['slaves'] = array_values(array_map(function ($slave) {
            /** @var Slave $slave */
            return $slave->asJson();
        }, $this->slaves));

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    public function setWorkingDirectory($workingDir)
    {
        $this->workingDirectory = $workingDir;
    }

    protected function commandRestart(array $data, Connection $conn)
    {
        if ($this->shutdownLock) {
            $conn->write('Shutdown already in progress.');
            $conn->end();

            return;
        }

        $this->shutdownLock = true;

        $slaves = $this->slaves;

        $this->gracefulShutdown(array_pop($slaves), $slaves, $conn);
    }

    protected function commandStop(array $data, Connection $conn)
    {
        if ($this->shutdownLock) {
            $conn->write('Shutdown already in progress.');
            $conn->end();

            return;
        }

        $this->allowNewInstances = false;
        $this->shutdownLock      = true;

        $slaves = $this->slaves;

        $this->gracefulShutdown(array_pop($slaves), $slaves, $conn, \Closure::bind(function () {
            $this->logger->info('Exited.');
            $this->loop->stop();
            exit;
        }, $this));
    }

    /**
     * Graceful shutdown slaves.
     *
     * @param Slave         $slave
     * @param array         $slaves
     * @param Connection    $client
     * @param callable|null $callback
     */
    private function gracefulShutdown(Slave $slave, $slaves, Connection $client, callable $callback = null)
    {
        $slave->setStatus(Slave::STATUS_SHUTDOWN);

        /** @var Connection $connection */
        $slave->getConnection()->on('close', \Closure::bind(function () use ($slave, $slaves, $client, $callback) {
            $message = sprintf("Shutdown http://%s:%s\n", $slave->getHost(), $slave->getPort());
            $this->logger->info($message);
            $client->write($message);
            if (count($slaves) > 0) {
                $this->gracefulShutdown(array_pop($slaves), $slaves, $client, $callback);
            } else {
                $this->shutdownLock = false;
                if ($callback !== null) {
                    $callback();
                }
                $client->write('Cluster fully shutdown.');
                $client->end();
            }
        }, $this));
        $client->write(sprintf('Try shutdown http://%s:%s', $slave->getHost(), $slave->getPort()));
        $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));
    }

    protected function commandStatus(array $data, Connection $conn)
    {
        $response = $this->clusterStatusAsJson();
        printf("%s\n", $response);
        $conn->end($response);
    }

    protected function commandPing(array $data, Connection $conn)
    {
        foreach ($this->slaves as $idx => &$slave) {
            if ($slave->equalsByPid($data['pid'])) {
                $slave->setMemory($data['memory']);
                $slave->setBornAt($data['born_at']);
                $slave->setPingAt($data['ping_at']);

                if (!$this->hasShutdownWorkers()) {
                    if ($slave->getMemory() > $this->workerMemoryLimit) {
                        $this->logger->warning(sprintf(
                            "Worker memory %s of limit %s exceeded.\n", $slave->getMemory(), $this->workerMemoryLimit
                        ));
                        $slave->setStatus(Slave::STATUS_SHUTDOWN);
                        $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));

                        break;
                    }

                    $cpuLimit = 15;
                    $cpuUsage = (int)shell_exec("ps -p {$slave->getPid()} -o %cpu | tail -n 1");
                    if ($cpuUsage > $cpuLimit) {
                        $this->logger->warning(sprintf("Worker cpu usage %s of limit %s exceeded.\n", $cpuLimit, $cpuUsage));
                        $slave->setStatus(Slave::STATUS_SHUTDOWN);
                        $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));

                        break;
                    }
                }
                break;
            }
        }
    }

    private function hasShutdownWorkers()
    {
        $hasShutdown = false;
        foreach ($this->slaves as $slave) {
            if ($slave->getStatus() === Slave::STATUS_SHUTDOWN) {
                $hasShutdown = true;
                break;
            }
        }

        return $hasShutdown || $this->shutdownLock;
    }

    protected function commandRegister(array $data, Connection $conn)
    {
        if (count($this->slaves) === $this->slavesCount) {
            $conn->end();

            return;
        }

        $newSlave = new Slave();
        $newSlave->setPid($data['pid']);
        $newSlave->setPort($data['port']);
        $newSlave->setHost($this->host);
        $newSlave->setConnection($conn);
        $newSlave->setPingAt(date('Y-m-d H:i:s O'));
        $newSlave->setBornAt(date('Y-m-d H:i:s O'));

        $isNew = count(array_filter($this->slaves, function ($slave) use ($newSlave) {
                /** @var Slave $slave */
                return $newSlave->equals($slave);
            })) === 0;

        if ($isNew) {
            $this->logger->info(sprintf("New slave %s up and ready.\n", $newSlave->getPort()));
            $this->slaves[] = $newSlave;
        }
    }

    protected function commandUnregister(array $data)
    {
        $pid = $data['pid'];
        $this->logger->warning(sprintf("Slave died. (pid %d)\n", $pid));
        foreach ($this->slaves as $idx => $slave) {
            if ($slave->equalsByPid($pid)) {
                $this->removeSlave($idx);
            }
        }
        $this->checkSlaves();
    }

    protected function removeSlave($idx)
    {
        $slave = $this->slaves[$idx];
        $this->logger->warning(sprintf("Die slave %s on port %s\n", $slave->getPid(), $slave->getPort()));
        $slave->getConnection()->close();
        unset($this->slaves[$idx]);

        /** @noinspection PhpParamsInspection */
        $this->loop->addTimer(2, \Closure::bind(function () {
            while (($pid = pcntl_waitpid(-1, $pidStatus, WNOHANG)) > 0) {
                $this->logger->debug(sprintf('Success wait child pid %s.', $pid));
            }
        }, $this));

        $this->checkSlaves();
    }

    /**
     * TODO: возможно ситуация при которой кол-во slave процессов создастся больше чем нужно,
     * TODO: из-за того что уже созданные еще не успели зарегаться.
     */
    protected function checkSlaves()
    {
        if (!$this->running || !$this->allowNewInstances) {
            return;
        }

        $slavesCount = count($this->slaves);
        if ($this->slavesCount > $slavesCount) {
            $this->runSlaves($this->slavesCount - $slavesCount);
        }
    }

    /**
     * Returning active slaves.
     * @return array
     */
    protected function activeSlaves()
    {
        return array_filter($this->slaves, function ($slave) {
            /** @var Slave $slave */
            return $slave->getStatus() === Slave::STATUS_OK;
        });
    }

    /**
     * Create new slave instance.
     */
    private function newInstance()
    {
        $pid = pcntl_fork();
        if (!$pid) {
            try {
                chdir($this->workingDirectory);
                new ProcessSlave($this->host, $this->port, $this->getBridge(), $this->appBootstrap, $this->appenv, $this->logFile);
            } catch (\Exception $e) {
                foreach ($this->slaves as $idx => $slave) {
                    if ($slave->equalsByPid(getmypid())) {
                        $this->removeSlave($idx);
                    }
                }
                $this->logger->error($e->getMessage(), $e->getTrace());
            }
            exit;
        }
    }
}

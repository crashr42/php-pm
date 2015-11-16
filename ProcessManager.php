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
     * @var array
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
    protected $slaveCount = 1;

    /**
     * @var bool
     */
    protected $waitForSlaves = true;

    /**
     * Whether the server is up and thus creates new slaves when they die or not.
     *
     * @var bool
     */
    protected $run = false;

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
     * @var
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
     * @var
     */
    private $workingDirectory;

    /**
     * Create process manager.
     *
     * @param int    $port
     * @param string $host
     * @param int    $slaveCount
     * @param        $workerMemoryLimit
     * @param string $logFile
     *
     * @throws \Exception
     */
    public function __construct($port = 8080, $host = '127.0.0.1', $slaveCount = 8, $workerMemoryLimit, $logFile)
    {
        $this->slaveCount = $slaveCount;
        $this->host = $host;
        $this->port = $port;
        $this->workerMemoryLimit = $workerMemoryLimit * 1024 * 1024;

        $lineFormatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%', null, false, true);

        $this->logFile = $logFile;
        $this->logger = new Logger(static::class);
        $this->logger->pushHandler(new StreamHandler($logFile));
        $this->logger->pushHandler((new ErrorLogHandler())->setFormatter($lineFormatter));

        $this->logger->debug(sprintf('Workers: %s', $slaveCount));
        $this->logger->debug(sprintf('Worker memory limit: %s bytes', $this->workerMemoryLimit));
        $this->logger->debug(sprintf('Host: %s:%s', $host, $port));
    }

    public function fork()
    {
        if ($this->run) {
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
        $this->loop = Factory::create();
        $this->controller = new Server($this->loop);
        $this->controller->on('connection', [$this, 'onSlaveConnection']);
        $this->controller->listen($this->port, $this->host);
        $http = new \React\Http\Server($this->controller);
        $http->on('request', \Closure::bind(function (Request $request, Response $response) {
            $response->writeHead();
            $response->end(json_encode($this->status()));
        }, $this));

        $this->web = new Server($this->loop);
        $this->web->on('connection', [$this, 'onWeb']);
        $this->web->listen($this->port + 1, $this->host);

        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newInstance();
        }

        $this->run = true;
        $this->loop();
    }

    /**
     * @param Connection $incoming
     */
    public function onWeb(Connection $incoming)
    {
        do {
            $slaves = array_values($this->activeSlaves());
            $slaveId = $this->getNextSlave();
        } while (!array_key_exists($slaveId, $slaves));

        $port = $slaves[$slaveId]['port'];
        $client = stream_socket_client(sprintf('tcp://%s:%s', $this->host, $port));
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
            $this->onData($data, $conn);
        }, $this));
        $conn->on('close', \Closure::bind(function () use ($conn) {
            foreach ($this->slaves as $idx => $slave) {
                if ($slave['connection'] === $conn) {
                    $this->removeSlave($idx);
                }
            }
        }, $this));
    }

    public function onData($data, Connection $conn)
    {
        $this->processMessage($data, $conn);
    }

    public function processMessage($data, Connection $conn)
    {
        $data = json_decode($data, true);

        $method = 'command'.ucfirst($data['cmd']);
        if (is_callable([$this, $method])) {
            $this->$method($data, $conn);
        }
    }

    private function status()
    {
        $data['pid'] = getmypid();
        $data['host'] = $this->host;
        $data['port'] = $this->port;
        if ($this->shutdownLock) {
            $data['shutdown'] = true;
        }
        $data['slaves'] = array_values(array_map(function ($slave) {
            return array_diff_key($slave, ['connection' => null]);
        }, $this->slaves));

        return $data;
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
        $this->shutdownLock = true;

        $slaves = $this->slaves;

        $this->gracefulShutdown(array_pop($slaves), $slaves, $conn, \Closure::bind(function () {
            $this->logger->info('Exited.');
            $this->loop->stop();
            exit;
        }, $this));
    }

    private function gracefulShutdown($slave, $slaves, Connection $client, callable $callback = null)
    {
        foreach ($this->slaves as $idx => $origSlave) {
            if ($slave === $origSlave) {
                $this->slaves[$idx]['shutdown'] = true;
            }
        }

        /** @var Connection $connection */
        $connection = $slave['connection'];
        $connection->on('close', \Closure::bind(function () use ($slave, $slaves, $connection, $client, $callback) {
            $message = sprintf("Shutdown http://%s:%s\n", $slave['host'], $slave['port']);
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
        $client->write(sprintf('Try shutdown http://%s:%s', $slave['host'], $slave['port']));
        $connection->write(json_encode(['cmd' => 'shutdown']));
    }

    protected function commandStatus(array $data, Connection $conn)
    {
        $response = json_encode($this->status());
        printf("%s\n", $response);
        $conn->end($response);
    }

    protected function commandPing(array $data, Connection $conn)
    {
        foreach ($this->slaves as $idx => &$slave) {
            if ($slave['pid'] === $data['pid']) {
                $slave['memory'] = $data['memory'];
                $slave['born_at'] = $data['born_at'];
                $slave['ping_at'] = $data['ping_at'];
                if ($data['memory'] > $this->workerMemoryLimit && !$this->hasShutdownWorkers()) {
                    $this->logger->warning(sprintf("Worker memory limit %s exceeded.\n", $this->workerMemoryLimit));
                    $slave['shutdown'] = true;
                    $slave['connection']->write(json_encode(['cmd' => 'shutdown']));
                }
                break;
            }
        }
    }

    private function hasShutdownWorkers()
    {
        $hasShutdown = false;
        foreach ($this->slaves as $slave) {
            if (array_key_exists('shutdown', $slave) && $slave['shutdown']) {
                $hasShutdown = true;
                break;
            }
        }

        return $hasShutdown;
    }

    protected function commandRegister(array $data, Connection $conn)
    {
        if (count($this->slaves) === $this->slaveCount) {
            $conn->end();

            return;
        }

        $pid = (int)$data['pid'];
        $port = (int)$data['port'];
        $newSlave = [
            'pid'        => $pid,
            'port'       => $port,
            'host'       => $this->host,
            'connection' => $conn,
        ];
        $exists = false;
        foreach ($this->slaves as $slave) {
            if ($slave['port'] === $newSlave['port']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $this->logger->info(sprintf("New slave %s up and ready.\n", $newSlave['port']));
            $this->slaves[] = $newSlave;
        }
    }

    protected function commandUnregister(array $data)
    {
        $pid = (int)$data['pid'];
        $this->logger->warning(sprintf("Slave died. (pid %d)\n", $pid));
        foreach ($this->slaves as $idx => $slave) {
            if ($slave['pid'] === $pid) {
                $this->removeSlave($idx);
            }
        }
        $this->checkSlaves();
    }

    protected function removeSlave($idx)
    {
        $slave = $this->slaves[$idx];
        $this->logger->warning(sprintf("Die slave %s on port %s\n", $slave['pid'], $slave['port']));
        $slave['connection']->close();
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
        if (!$this->run || !$this->allowNewInstances) {
            return;
        }

        $i = count($this->slaves);
        if ($this->slaveCount > $i) {
            $this->waitForSlaves = true;
            for (; $i < $this->slaveCount; $i++) {
                $this->newInstance();
            }
        }
    }

    protected function activeSlaves()
    {
        return array_filter($this->slaves, function ($slave) {
            return !array_key_exists('shutdown', $slave);
        });
    }

    private function loop()
    {
        $this->loop->run();
    }

    private function newInstance()
    {
        $pid = pcntl_fork();
        if (!$pid) {
            try {
                chdir($this->workingDirectory);
                new ProcessSlave($this->host, $this->port, $this->getBridge(), $this->appBootstrap, $this->appenv, $this->logFile);
            } catch (\Exception $e) {
                foreach ($this->slaves as $idx => $slave) {
                    if ($slave['pid'] === getmypid()) {
                        $this->removeSlave($idx);
                    }
                }
                $this->logger->error($e->getMessage(), $e->getTrace());
            }
            exit;
        }
    }
}

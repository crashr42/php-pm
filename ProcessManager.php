<?php

namespace PHPPM;

use Closure;
use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPPM\Config\ConfigReader;
use PHPPM\Control\ControlCommand;
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
    public $loop;

    /**
     * @var Server
     */
    protected $controller;

    /**
     * @var Server
     */
    protected $web;

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
     * @var Logger
     */
    public $logger;

    /**
     * @var bool
     */
    public $shutdownLock = false;

    /**
     * @var bool
     */
    public $allowNewInstances = true;

    /**
     * @var int
     */
    public $waitedSlaves = 0;

    /**
     * @var ConfigReader
     */
    public $config;

    /**
     * Create process manager.
     *
     * @param ConfigReader $config
     */
    public function __construct(ConfigReader $config)
    {
        $this->config = $config;

        cli_set_process_title('react master');

        $lineFormatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%', null, true, true);

        $this->logger = new Logger(static::class);
        $this->logger->pushHandler(new StreamHandler($config->log_file));
        $this->logger->pushHandler((new ErrorLogHandler())->setFormatter($lineFormatter));

        set_error_handler(Closure::bind(function ($errno, $errstr, $errfile, $errline) {
            $this->logger->crit(sprintf('Fatal error "[%s] %s" in %s:%s', $errno, $errstr, $errfile, $errline), func_get_args());
        }, $this));

        $this->logger->debug(sprintf('Workers: %s', $config->workers));
        $this->logger->debug(sprintf('Worker memory limit: %s bytes', $config->worker_memory_limit));
        $this->logger->debug(sprintf('Host: %s:%s', $config->host, $config->port));
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

    public function run()
    {
        $this->loop       = Factory::create();
        $this->controller = new Server($this->loop);
        $this->controller->on('connection', [$this, 'onSlaveConnection']);
        $this->controller->listen($this->config->port, $this->config->host);

        $http = new \PHPPM\Server($this->controller);
        /** @noinspection PhpUnusedParameterInspection */
        $http->on('request', Closure::bind(function (Request $request, Response $response) {
            $response->writeHead();
            $response->end($this->clusterStatusAsJson());
        }, $this));

        $this->web = new Server($this->loop);
        $this->web->on('connection', [$this, 'onWeb']);
        $this->web->listen($this->config->port + 1, $this->config->host);

        $this->runSlaves();

        $this->running = true;

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(1, Closure::bind(function () {
            $this->checkSlaves();
        }, $this));

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(1, Closure::bind(function () {
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
        if ($this->config->request_timeout === null) {
            return 30 + ProcessSlave::PING_TIMEOUT * 3;
        }

        return $this->config->request_timeout;
    }

    /**
     * Run slaves one by one.
     */
    private function runSlaves()
    {
        if (!$this->allowNewInstances) {
            return;
        }

        $this->allowNewInstances = false;

        $count = 1;

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
        $client   = stream_socket_client(sprintf('tcp://%s:%s', $this->config->host, $port));
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
            $this->index = 0;
        }

        return $this->index;
    }

    public function onSlaveConnection(Connection $conn)
    {
        $conn->on('data', Closure::bind(function ($data) use ($conn) {
            $this->processControlCommand($data, $conn);
        }, $this));
        $conn->on('close', Closure::bind(function () use ($conn) {
            foreach ($this->slaves as $idx => $slave) {
                if ($slave->equalsByConnection($conn)) {
                    $this->removeSlave($idx);
                }
            }
        }, $this));
    }

    /**
     * Handle raw control command and process it.
     *
     * @param string $raw
     * @param Connection $connection
     */
    public function processControlCommand($raw, Connection $connection)
    {
        $data = json_decode($raw, true);

        $commandClass = sprintf('PHPPM\\Control\\Commands\\%sCommand', ucfirst($data['cmd']));
        if (!class_exists($commandClass)) {
            $this->logger->warning("Unknown command `{$raw}`. Class not found `{$commandClass}`.");
            $connection->close();

            return;
        }

        /** @var ControlCommand $command */
        $command = new $commandClass;
        $command->handle($data, $connection, $this);
    }

    public function clusterStatusAsJson()
    {
        $data['pid']                 = getmypid();
        $data['host']                = $this->config->host;
        $data['port']                = $this->config->port;
        $data['shutdown_lock']       = $this->shutdownLock;
        $data['waited_slaves']       = $this->waitedSlaves;
        $data['slaves_count']        = count($this->slaves);
        $data['allow_new_instances'] = $this->allowNewInstances;

        $data['slaves'] = array_values(array_map(function ($slave) {
            /** @var Slave $slave */
            return $slave->asJson();
        }, $this->slaves));

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get all slaves.
     *
     * @return Slave[]
     */
    public function getSlaves()
    {
        return $this->slaves;
    }

    /**
     * Add new slave.
     *
     * @param Slave $slave
     */
    public function addSlave(Slave $slave)
    {
        $this->slaves[] = $slave;
    }

    /**
     * Graceful shutdown slaves.
     *
     * @param Slave $slave
     * @param array $slaves
     * @param Connection $client
     * @param callable|null $callback
     */
    public function gracefulShutdown(Slave $slave, $slaves, Connection $client, callable $callback = null)
    {
        $slave->setStatus(Slave::STATUS_SHUTDOWN);

        /** @var Connection $connection */
        $slave->getConnection()->on('close', Closure::bind(function () use ($slave, $slaves, $client, $callback) {
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
                $client->write('Last worker shutdown.');
                $client->end();
            }
        }, $this));
        $client->write(sprintf('Try shutdown http://%s:%s', $slave->getHost(), $slave->getPort()));
        $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));
    }

    /**
     * Has workers in shutdown status?
     *
     * @return bool
     */
    public function hasShutdownWorkers()
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

    public function removeSlave($idx)
    {
        $slave = $this->slaves[$idx];
        $this->logger->warning(sprintf("Die slave %s on port %s\n", $slave->getPid(), $slave->getPort()));
        $slave->getConnection()->close();
        unset($this->slaves[$idx]);

        /** @noinspection PhpParamsInspection */
        $this->loop->addTimer(2, Closure::bind(function () {
            while (($pid = pcntl_waitpid(-1, $pidStatus, WNOHANG)) > 0) {
                $this->logger->debug(sprintf('Success wait child pid %s.', $pid));
            }
        }, $this));

        $this->checkSlaves();
    }

    /**
     * Check slaves count and run new slave if count less then needed.
     */
    public function checkSlaves()
    {
        if (!$this->running || !$this->allowNewInstances || $this->waitedSlaves > 0) {
            return;
        }

        $slavesCount = count($this->slaves);
        if ($this->config->workers > $slavesCount) {
            $this->logger->warning('Slaves count less then needed.');

            $this->runSlaves();
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
        $this->logger->debug('Fork new slave.');

        $this->waitedSlaves++;
        $pid = pcntl_fork();
        if (!$pid) {
            try {
                chdir($this->config->working_directory);
                new ProcessSlave($this->config);
            } catch (Exception $e) {
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

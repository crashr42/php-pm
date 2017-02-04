<?php

namespace PHPPM;

use Closure;
use Exception;
use PHPPM\Channels\BalancerControlChannel;
use PHPPM\Channels\MasterControlChannel;
use PHPPM\Config\ConfigReader;
use PHPPM\Control\Commands\ShutdownCommand;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Socket\Server;

class ProcessManager
{
    /**
     * @var SlavesCollection
     */
    protected $slaves;

    /**
     * @var LoopInterface
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
     * Whether the server is up and thus creates new slaves when they die or not.
     *
     * @var bool
     */
    protected $running = false;

    /**
     * @var \Monolog\Logger|Logger
     */
    protected $logger;

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
    protected $config;

    /**
     * @return ConfigReader
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * @return \Monolog\Logger|Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Create process manager.
     *
     * @param ConfigReader $config
     */
    public function __construct(ConfigReader $config)
    {
        $this->config = $config;
        $this->config->slaves_min_port = $config->port + 2;
        $this->config->slaves_max_port = $this->config->slaves_min_port + 90;
        $this->slaves = new SlavesCollection();

        cli_set_process_title('react master');

        $this->logger = Logger::get(static::class, $config->log_file);

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            $this->logger->crit(sprintf('"[%s] %s" in %s:%s', $errno, $errstr, $errfile, $errline), func_get_args());
        }, E_STRICT | E_ERROR) ;

        $this->logger->info('Config: '.json_encode($config->getArrayCopy(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Run main loop and start slaves.
     */
    public function run()
    {
        $this->loop = Factory::create();

        $mc = new MasterControlChannel($this, $this->loop);
        $mc->on('done', function () {
            new BalancerControlChannel($this, $this->loop);

            $this->runSlaves();

            $this->running = true;

            /** @noinspection PhpParamsInspection */
            $this->loop->addPeriodicTimer(1, Closure::bind(function () {
                $this->checkSlaves();
            }, $this));

            /** @noinspection PhpParamsInspection */
            $this->loop->addPeriodicTimer(1, Closure::bind(function () {
                foreach ($this->slaves->getSlaves() as $slave) {
                    if ($slave->getPingAt() === null) {
                        continue;
                    }

                    if ((time() - strtotime($slave->getPingAt())) > $this->slavePingTimeout()) {
                        $this->logger->info("Timeout ping from worker at pid {$slave->getPid()}. Killing it ...");
                        if (posix_kill($slave->getPid(), SIGKILL)) {
                            $this->logger->warn("Killed worker at pid {$slave->getPid()}.");
                            $this->removeSlave($slave);
                        } else {
                            $this->logger->warn("Can't kill worker at pid {$slave->getPid()}.");
                        }
                    }
                }
            }, $this));
        });
        $mc->run();

        $this->loop->run();
    }

    /**
     * Calculate slave ping timeout.
     *
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

        $this->forkSlave();

        $this->allowNewInstances = true;
    }

    /**
     * Check slaves count and run new slave if count less then needed.
     */
    public function checkSlaves()
    {
        if (!$this->running || !$this->allowNewInstances || $this->waitedSlaves > 0 || $this->shutdownLock) {
            return;
        }

        $slavesCount = count($this->slaves->getSlaves());
        if ($this->config->workers > $slavesCount) {
            $this->logger->warning('Slaves count less then needed.');

            $this->runSlaves();
        }
    }

    /**
     * Create new slave instance.
     */
    public function forkSlave()
    {
        $this->logger->debug('Fork new slave.');

        if (count($this->slavesCollection()) === $this->config->workers) {
            $this->logger->warning('All slaves already spawned. Reject slave.');

            return;
        }

        $this->waitedSlaves++;
        $pid = pcntl_fork();
        if (!$pid) {
            try {
                chdir($this->config->working_directory);
                new ProcessSlave($this->config);
            } catch (Exception $e) {
                foreach ($this->slaves->getSlaves() as $slave) {
                    if ($slave->equalsByPid(getmypid())) {
                        $this->removeSlave($slave);
                    }
                }
                $this->logger->error($e->getMessage(), $e->getTrace());
            }
            exit;
        }
    }

    /**
     * Get cluster status as json.
     *
     * @return string
     */
    public function clusterStatusAsJson()
    {
        $data['pid']                 = getmypid();
        $data['host']                = $this->config->host;
        $data['port']                = $this->config->port;
        $data['shutdown_lock']       = $this->shutdownLock;
        $data['waited_slaves']       = $this->waitedSlaves;
        $data['slaves_count']        = count($this->slaves);
        $data['allow_new_instances'] = $this->allowNewInstances;
        $data['slaves_control_port'] = $this->config->slaves_control_port;

        $data['slaves'] = array_values(array_map(function ($slave) {
            /** @var Slave $slave */
            return $slave->asJson();
        }, $this->slaves->getSlaves()));

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        $slave->getConnection()->on('close', function () use ($slave, $slaves, $client, $callback) {
            $message = sprintf('Shutdown http://%s:%s', $slave->getHost(), $slave->getPort());
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
        });
        $client->write(sprintf('Try shutdown http://%s:%s', $slave->getHost(), $slave->getPort()));

        $slave->getConnection()->write((new ShutdownCommand())->serialize());
    }

    /**
     * Has workers in shutdown status?
     *
     * @return bool
     */
    public function hasShutdownSlaves()
    {
        return $this->slaves->hasShutdownSlaves() || $this->shutdownLock;
    }

    /**
     * Remove slave from collection and stop it.
     *
     * @param Slave $slave
     */
    private function removeSlave(Slave $slave)
    {
        $this->logger->warning(sprintf('Die slave %s on port %s', $slave->getPid(), $slave->getPort()));

        $this->slaves->removeSlave($slave);

        $slave->getConnection()->close();

        /** @noinspection PhpParamsInspection */
        $this->loop->addTimer(2, Closure::bind(function () {
            while (($pid = pcntl_waitpid(-1, $pidStatus, WNOHANG)) > 0) {
                $this->logger->debug(sprintf('Success wait child pid %s.', $pid));
            }
        }, $this));

        $this->checkSlaves();
    }

    /**
     * Get slaves collection.
     *
     * @return SlavesCollection
     */
    public function slavesCollection()
    {
        return $this->slaves;
    }
}

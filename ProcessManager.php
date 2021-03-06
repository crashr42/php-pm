<?php

namespace PHPPM;

use Closure;
use Exception;
use PHPPM\Channels\BalancerControlChannel;
use PHPPM\Channels\MasterControlChannel;
use PHPPM\Config\ConfigReader;
use PHPPM\Control\Commands\ShutdownCommand;
use PHPPM\Log\Logger;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Socket\Server;

class ProcessManager
{
    /**
     * Contains all workers spawned by master.
     *
     * @var WorkersCollection
     */
    protected $workers;

    /**
     * Main event loop.
     *
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Whether the server is up and thus creates new workers when they die or not.
     *
     * @var bool
     */
    protected $running = false;

    /**
     * Main logger.
     *
     * @var \Monolog\Logger|Logger
     */
    protected $logger;

    /**
     * Disallow spawn new workers if master in shutdown mode.
     *
     * @var bool
     */
    protected $shutdownLock = false;

    /**
     * hmm
     *
     * @var bool
     */
    protected $allowNewInstances = true;

    /**
     * Waited workers count.
     *
     * @var int
     */
    protected $waitedWorkers = 0;

    /**
     * Main config.
     *
     * @var ConfigReader
     */
    protected $config;

    /**
     * Create process manager.
     *
     * @param ConfigReader $config
     */
    public function __construct(ConfigReader $config)
    {
        $this->config                   = $config;
        $this->config->workers_min_port = $config->port + 2; // reverse 5500 and 5501
        $this->config->workers_max_port = $this->config->workers_min_port + ProcessWorker::MAX_WORKERS;
        $this->config->master_pid       = getmypid();
        $this->workers                  = new WorkersCollection();

        cli_set_process_title(sprintf('[%d] react master', getmypid()));

        $this->logger = Logger::get(static::class, $config->log_file, $config->log_level, getmypid());

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            $this->logger->crit(sprintf('"[%s] %s" in %s:%s', $errno, $errstr, $errfile, $errline), func_get_args());
        }, E_ALL ^ E_WARNING);

        $this->logger->info('Config: '.json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Run main loop and start workers.
     */
    public function run()
    {
        $this->loop = Factory::create();

        $mc = new MasterControlChannel($this, $this->loop);
        $mc->on('done', function () {
            new BalancerControlChannel($this, $this->loop);

            $this->runWorkers();

            $this->running = true;

            /** @noinspection PhpParamsInspection */
            $this->loop->addPeriodicTimer(1, Closure::bind(function () {
                $this->checkWorkers();
            }, $this));

            /** @noinspection PhpParamsInspection */
            $this->loop->addPeriodicTimer(1, Closure::bind(function () {
                foreach ($this->workers->all() as $worker) {
                    if ($worker->getPingAt() === null) {
                        continue;
                    }

                    if ((time() - strtotime($worker->getPingAt())) > $this->workerPingTimeout()) {
                        $this->logger->info("Timeout ping from worker at pid {$worker->getPid()}. Killing it ...");
                        if (posix_kill($worker->getPid(), SIGKILL)) {
                            $this->logger->warn("Killed worker at pid {$worker->getPid()}.");
                            $this->removeWorker($worker);
                        } else {
                            $this->logger->warn("Can't kill worker at pid {$worker->getPid()}.");
                        }
                    }
                }
            }, $this));

            $this->logger->info('Cluster up!');
        });
        $mc->run();

        $this->loop->run();
    }

    /**
     * Calculate worker ping timeout.
     *
     * @return int
     */
    private function workerPingTimeout()
    {
        if ($this->config->request_timeout === null) {
            return 30 + ProcessWorker::PING_TIMEOUT * 3;
        }

        return $this->config->request_timeout;
    }

    /**
     * Run workers one by one.
     */
    private function runWorkers()
    {
        if (!$this->allowNewInstances) {
            return;
        }

        $this->allowNewInstances = false;

        $this->forkWorker();

        $this->allowNewInstances = true;
    }

    /**
     * Check workers count and run new worker if count less then needed.
     */
    public function checkWorkers()
    {
        if (!$this->running || !$this->allowNewInstances || $this->waitedWorkers > 0 || $this->shutdownLock) {
            return;
        }

        $workersCount = count($this->workers->all());
        if ($this->config->workers > $workersCount) {
            $this->logger->warning('Workers count less then needed.');

            $this->runWorkers();
        }
    }

    /**
     * Create new worker instance.
     */
    public function forkWorker()
    {
        $this->logger->debug('Fork new worker.');

        if (count($this->workers()) === $this->config->workers) {
            $this->logger->warning('All workers already spawned. Reject worker.');

            return;
        }

        $this->waitedWorkers++;
        $pid = pcntl_fork();
        if (!$pid) {
            try {
                chdir($this->config->working_directory);
                new ProcessWorker($this->config);
            } catch (Exception $e) {
                foreach ($this->workers->all() as $worker) {
                    if ($worker->equalsByPid(getmypid())) {
                        $this->removeWorker($worker);
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
        $data['pid']                  = getmypid();
        $data['host']                 = $this->config->host;
        $data['port']                 = $this->config->port;
        $data['shutdown_lock']        = $this->shutdownLock;
        $data['waited_workers']       = $this->waitedWorkers;
        $data['workers_count']        = count($this->workers);
        $data['allow_new_instances']  = $this->allowNewInstances;
        $data['workers_control_port'] = $this->config->workers_control_port;

        $data['workers'] = array_values(array_map(function ($worker) {
            /** @var Worker $worker */
            return $worker->asJson();
        }, $this->workers->all()));

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Graceful shutdown workers.
     *
     * @param Worker $worker
     * @param array $workers
     * @param Connection $client
     * @param callable|null $callback
     */
    public function gracefulShutdown(Worker $worker, $workers, Connection $client, callable $callback = null)
    {
        $worker->setStatus(Worker::STATUS_SHUTDOWN);

        /** @var Connection $connection */
        $worker->getConnection()->on('close', function () use ($worker, $workers, $client, $callback) {
            $message = sprintf('Shutdown http://%s:%s', $worker->getHost(), $worker->getPort());
            $this->logger->info($message);
            $client->write($message);
            if (count($workers) > 0) {
                $this->gracefulShutdown(array_pop($workers), $workers, $client, $callback);
            } else {
                $this->shutdownLock = false;
                if ($callback !== null) {
                    $callback();
                }
                $client->write('Last worker shutdown.');
                $client->end();
            }
        });
        $client->write(sprintf('Try shutdown http://%s:%s', $worker->getHost(), $worker->getPort()));

        $worker->getConnection()->write(ShutdownCommand::build());
    }

    /**
     * Has workers in shutdown status?
     *
     * @return bool
     */
    public function hasShutdownWorkers()
    {
        return $this->workers->hasShutdownWorkers() || $this->shutdownLock;
    }

    /**
     * Remove worker from collection and stop it.
     *
     * @param Worker $worker
     */
    public function removeWorker(Worker $worker)
    {
        $this->logger->warning(sprintf('Die worker %s on port %s', $worker->getPid(), $worker->getPort()));

        $this->workers->removeWorker($worker);

        $worker->getConnection()->close();

        /** @noinspection PhpParamsInspection */
        $this->loop->addTimer(2, Closure::bind(function () {
            while (($pid = pcntl_waitpid(-1, $pidStatus, WNOHANG)) > 0) {
                $this->logger->debug(sprintf('Success wait child pid %s.', $pid));
            }
        }, $this));

        $this->checkWorkers();
    }

    /**
     * Add worker to collection and wait it register.
     *
     * @param Worker $worker
     */
    public function addWorker(Worker $worker)
    {
        $this->waitedWorkers--;

        $this->workers()->addWorker($worker);
    }

    /**
     * Get workers collection.
     *
     * @return WorkersCollection
     */
    public function workers()
    {
        return $this->workers;
    }

    /**
     * Move cluster to shutdown mode or revert to normal state.
     * In shutdown mode cluster not spawn new workers.
     *
     * @param $shutdownLock
     */
    public function setShutdownLock($shutdownLock)
    {
        if ($shutdownLock) {
            cli_set_process_title(sprintf('[%d] react master / shutdown', getmypid()));
        } else {
            cli_set_process_title(sprintf('[%d] react master', getmypid()));
        }

        $this->shutdownLock = $shutdownLock;
    }

    /**
     * Check cluster in shutdown mode.
     *
     * @return bool
     */
    public function inShutdownLock()
    {
        return $this->shutdownLock;
    }

    /**
     * Allow or disallow new workers instances.
     *
     * @param bool $allowNewInstances
     */
    public function setAllowNewInstances($allowNewInstances)
    {
        $this->allowNewInstances = $allowNewInstances;
    }

    /**
     * Don't wait any workers.
     */
    public function resetWaitedQueue()
    {
        $this->waitedWorkers = 0;
    }

    /**
     * Get main config.
     *
     * @return ConfigReader
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get main event loop.
     *
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * Get manager logger.
     *
     * @return \Monolog\Logger|Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }
}

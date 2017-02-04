<?php

namespace PHPPM;

use Closure;
use PHPPM\Config\ConfigReader;
use PHPPM\Control\Commands\PingCommand;
use PHPPM\Control\Commands\RegisterCommand;
use PHPPM\Control\Commands\ShutdownCommand;
use PHPPM\Control\Commands\UnregisterCommand;
use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\ConnectionException;

class ProcessSlave
{
    const PING_TIMEOUT              = 5;
    const SHUTDOWN_TIMEOUT          = 1;
    const FAIL_CHECK_BEFORE_RESTART = 3;
    const MAX_WORKERS               = 200;

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var resource
     */
    protected $client;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    /**
     * @var bool
     */
    protected $shutdown = false;

    /**
     * @var bool
     */
    protected $processing = false;

    /**
     * @var bool
     */
    protected $failChecked = 0;

    /**
     * @var int
     */
    protected $waitFailChecked = 0;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ConfigReader
     */
    protected $config;

    /**
     * @var Bus
     */
    protected $bus;

    /**
     * @return \Monolog\Logger|Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Create slave process.
     *
     * @param ConfigReader $config
     */
    public function __construct(ConfigReader $config)
    {
        $this->config = $config;

        $this->logger = Logger::get(static::class, $config->log_file);

        $this->bootstrap($config->bootstrap, $config->appenv);
        $this->connectToMaster();
        $this->loop->run();
    }

    /**
     * @return Bridges\BridgeInterface
     */
    protected function getBridge()
    {
        if (null === $this->bridge && $this->config->bridge) {
            if (true === class_exists($this->config->bridge)) {
                $bridgeClass = $this->config->bridge;
            } else {
                $bridgeClass = sprintf('PHPPM\Bridges\\%s', ucfirst($this->config->bridge));
            }

            $this->bridge = new $bridgeClass;
        }

        return $this->bridge;
    }

    /**
     * Bootstrap application.
     *
     * @param string $appBootstrap
     * @param string $appenv
     */
    protected function bootstrap($appBootstrap, $appenv)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv);
        }
    }

    /**
     * Connect to master process, start http interface and accept connections.
     */
    public function connectToMaster()
    {
        $bornAt = date('Y-m-d H:i:s O');

        $this->loop = Factory::create();

        $this->client     = stream_socket_client(sprintf('tcp://%s:%s', $this->config->host, $this->config->slaves_control_port));
        $this->connection = new Connection($this->client, $this->loop);
        $this->bus        = new Bus($this->connection, $this);
        $this->bus->on(ShutdownCommand::class, function () {
            $this->shutdown = true;
        });
        $this->bus->run();

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::PING_TIMEOUT, function () use ($bornAt) {
            $result = $this->bus->send((new PingCommand())->serialize([
                'pid'     => getmypid(),
                'memory'  => memory_get_usage(true),
                'born_at' => $bornAt,
                'ping_at' => date('Y-m-d H:i:s O'),
            ]));

            if (!$result) {
                $this->loop->stop();
            }
        });
        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::SHUTDOWN_TIMEOUT, function () {
            if ($this->shutdown) {
                $failChecked  = $this->failChecked >= self::FAIL_CHECK_BEFORE_RESTART;
                $waitChecked  = $this->waitFailChecked > self::SHUTDOWN_TIMEOUT * 10;
                $allowRestart = !$this->processing && ($failChecked || $waitChecked);
                if ($allowRestart) {
                    if ($waitChecked) {
                        $this->logger->warn('Not wait fail checking! Restarting.');
                    }
                    $this->logger->info(sprintf('Shutdown pid %s', getmypid()));
                    $this->shutdown();
                } else {
                    $this->logger->info(sprintf('Wait balancer checks and requests complete [pid: %s]', getmypid()));
                }

                $this->waitFailChecked++;
            }
        });

        $this->connection->on('close', Closure::bind(function () {
            $this->shutdown();
        }, $this));

        $socket = new \React\Socket\Server($this->loop);
        $http   = new \PHPPM\Server($socket);
        $http->on('request', [$this, 'onRequest']);

        $port = $this->config->slaves_min_port;
        while ($port < $this->config->slaves_max_port) {
            try {
                $socket->listen($port, $this->config->host);
                $this->port = $port;
                $this->logger->info(sprintf('Listen worker on uri http://%s:%s', $this->config->host, $port));
                cli_set_process_title(sprintf('react slave on port %s', $port));
                break;
            } catch (ConnectionException $e) {
                $port++;
            }
        }

        $this->bus->send((new RegisterCommand())->serialize(getmypid(), $port));
    }

    /**
     * Handle http request.
     *
     * @param Request $request
     * @param Response $response
     * @throws \Exception
     */
    public function onRequest(Request $request, Response $response)
    {
        if ($request->getPath() === $this->config->check_url) {
            $response->writeHead($this->shutdown ? 500 : 200);
            if ($this->shutdown) {
                $this->failChecked++;
            }

            return;
        }

        $this->logger->debug($request->getPath());

        $this->processing = true;
        if ($bridge = $this->getBridge()) {
            $bridge->onRequest($request, $response);
        } else {
            $response->writeHead('404');
            $response->end('No Bridge Defined.');
        }
        $this->processing = false;
    }

    /**
     * Shutdown worker.
     */
    protected function shutdown()
    {
        $this->logger->info(sprintf('Shutting slave process down (http://%s:%s)', $this->config->host, $this->port));
        if ($this->connection->isWritable()) {
            $this->bus->send((new UnregisterCommand())->serialize(getmypid()));
            $this->connection->close();
        }
        $this->loop->stop();

        exit;
    }
}

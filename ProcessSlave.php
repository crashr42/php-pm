<?php

namespace PHPPM;

use Closure;
use PHPPM\Config\ConfigReader;
use PHPPM\Control\Commands\RegisterCommand;
use PHPPM\Control\Commands\ShutdownCommand;
use PHPPM\Control\Commands\UnregisterCommand;
use PHPPM\Control\ControlCommand;
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
    private $port;

    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var ConfigReader
     */
    private $config;

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

    protected function shutdown()
    {
        $this->logger->info(sprintf("Shutting slave process down (http://%s:%s)\n", $this->config->host, $this->config->port));
        $this->bye();
        exit;
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

    protected function bootstrap($appBootstrap, $appenv)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv);
        }
    }

    public function connectToMaster()
    {
        $bornAt = date('Y-m-d H:i:s O');

        $this->loop       = Factory::create();
        $this->client     = stream_socket_client(sprintf('tcp://%s:%s', $this->config->host, $this->config->port));
        $this->connection = new Connection($this->client, $this->loop);

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::PING_TIMEOUT, Closure::bind(function () use ($bornAt) {
            $result = $this->connection->write(json_encode([
                'cmd'     => 'ping',
                'pid'     => getmypid(),
                'memory'  => memory_get_usage(true),
                'born_at' => $bornAt,
                'ping_at' => date('Y-m-d H:i:s O'),
            ]));
            if (!$result) {
                $this->loop->stop();
            }
        }, $this));
        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::SHUTDOWN_TIMEOUT, Closure::bind(function () {
            if ($this->shutdown) {
                $failChecked  = $this->failChecked >= self::FAIL_CHECK_BEFORE_RESTART;
                $waitChecked  = $this->waitFailChecked > self::SHUTDOWN_TIMEOUT * 10;
                $allowRestart = !$this->processing && ($failChecked || $waitChecked);
                if ($allowRestart) {
                    if ($waitChecked) {
                        $this->logger->warn('Not wait fail checking! Restarting.');
                    }
                    $this->logger->info(sprintf("Shutdown pid %s\n", getmypid()));
                    $this->shutdown();
                } else {
                    $this->logger->info(sprintf("Wait balancer checks and requests complete [pid: %s]\n", getmypid()));
                }

                $this->waitFailChecked++;
            }
        }, $this));

        $this->connection->on('data', Closure::bind(function ($raw) {
            if (($command = ControlCommand::find($raw)) && $command instanceof ShutdownCommand) {
                $this->shutdown = true;
            }
        }, $this));

        $this->connection->on('close', Closure::bind(function () {
            $this->shutdown();
        }, $this));

        $socket = new \React\Socket\Server($this->loop);
        $http   = new \PHPPM\Server($socket);
        $http->on('request', [$this, 'onRequest']);

        $port    = $this->config->port;
        $maxPort = $port + self::MAX_WORKERS;
        while ($port < $maxPort) {
            try {
                $socket->listen($port, $this->config->host);
                $this->port = $port;
                $this->logger->info(sprintf("Listen worker on uri http://%s:%s\n", $this->config->host, $port));
                cli_set_process_title(sprintf('react slave on port %s', $port));
                break;
            } catch (ConnectionException $e) {
                $port++;
            }
        }

        $this->connection->write((new RegisterCommand())->serialize(getmypid(), $port));
    }

    public function onRequest(Request $request, Response $response)
    {
        if ($request->getPath() === '/check') {
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

    public function bye()
    {
        if ($this->connection->isWritable()) {
            $this->connection->write((new UnregisterCommand())->serialize(getmypid()));
            $this->connection->close();
        }
        $this->loop->stop();
    }
}

<?php

namespace PHPPM;

use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\ConnectionException;
use React\Socket\Server;

class ProcessSlave
{
    const PING_TIMEOUT = 5;
    const RESTARTING_TIMEOUT = 1;

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
     * @var string
     */
    protected $bridgeName;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    /**
     * @var string|null
     */
    protected $appenv;

    /**
     * @var bool
     */
    protected $restarting = false;

    /**
     * @var bool
     */
    protected $processing = false;

    /**
     * @var string
     */
    private $ppmHost;

    /**
     * @var int
     */
    private $ppmPort;

    /**
     * @var int
     */
    private $port;

    public function __construct($ppmHost, $ppmPort, $bridgeName = null, $appBootstrap, $appenv)
    {
        $this->ppmHost = $ppmHost;
        $this->ppmPort = $ppmPort;
        $this->bridgeName = $bridgeName;
        $this->bootstrap($appBootstrap, $appenv);
        $this->connectToMaster();
        $this->loop->run();
    }

    protected function shutdown()
    {
        echo sprintf("Shutting slave process down (http://%s:%s)\n", $this->ppmHost, $this->port);
        $this->bye();
        exit;
    }

    /**
     * @return Bridges\BridgeInterface
     */
    protected function getBridge()
    {
        if (null === $this->bridge && $this->bridgeName) {
            if (true === class_exists($this->bridgeName)) {
                $bridgeClass = $this->bridgeName;
            } else {
                $bridgeClass = sprintf('PHPPM\Bridges\\%s', ucfirst($this->bridgeName));
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

        $this->loop = Factory::create();
        $this->client = stream_socket_client(sprintf('tcp://%s:%s', $this->ppmHost, $this->ppmPort));
        $this->connection = new Connection($this->client, $this->loop);

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::PING_TIMEOUT, \Closure::bind(function () use ($bornAt) {
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
        $this->loop->addPeriodicTimer(self::RESTARTING_TIMEOUT, \Closure::bind(function () {
            if ($this->restarting && !$this->processing) {
                echo sprintf("Shutdown %s\n", getmypid());
                $this->shutdown();
            }
        }, $this));

        $this->connection->on('data', \Closure::bind(function ($data) {
            $data = json_decode($data, true);
            if ($data['cmd'] === 'restart') {
                $this->restarting = true;
            }
        }, $this));

        $this->connection->on('close', \Closure::bind(function () {
            $this->shutdown();
        }, $this));

        $socket = new Server($this->loop);
        $http = new \React\Http\Server($socket);
        $http->on('request', [$this, 'onRequest']);

        $port = $this->ppmPort;
        $maxPort = $port + 100;
        while ($port < $maxPort) {
            try {
                $socket->listen($port, $this->ppmHost);
                $this->port = $port;
                echo sprintf("Listen worker on uri http://%s:%s\n", $this->ppmHost, $port);
                break;
            } catch (ConnectionException $e) {
                $port++;
            }
        }

        $this->connection->write(json_encode(['cmd' => 'register', 'pid' => getmypid(), 'port' => $port]));
    }

    public function onRequest(Request $request, Response $response)
    {
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
            $this->connection->write(json_encode(['cmd' => 'unregister', 'pid' => getmypid()]));
            $this->connection->close();
        }
        $this->loop->stop();
    }
}

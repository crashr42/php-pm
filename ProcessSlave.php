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

    public function __construct($bridgeName = null, $appBootstrap, $appenv)
    {
        $this->bridgeName = $bridgeName;
        $this->bootstrap($appBootstrap, $appenv);
        $this->connectToMaster();
        $this->loop->run();
    }

    protected function shutdown()
    {
        echo "SHUTTING SLAVE PROCESS DOWN\n";
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
        $bornIn = date('Y-m-d H:i:s O');

        $this->loop = Factory::create();
        $this->client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new Connection($this->client, $this->loop);

        /** @noinspection PhpParamsInspection */
        $this->loop->addPeriodicTimer(self::PING_TIMEOUT, \Closure::bind(function () use ($bornIn) {
            $result = $this->connection->write(json_encode([
                'cmd'     => 'ping',
                'pid'     => getmypid(),
                'memory'  => memory_get_usage(true),
                'born_at' => $bornIn,
                'ping_at' => date('Y-m-d H:i:s O'),
            ]));
            if (!$result) {
                $this->loop->stop();
            }
        }, $this));

        $this->connection->on('close', \Closure::bind(function () {
            $this->shutdown();
        }, $this));

        $socket = new Server($this->loop);
        $http = new \React\Http\Server($socket);
        $http->on('request', [$this, 'onRequest']);

        $port = 5501;
        while ($port < 5600) {
            try {
                $socket->listen($port);
                break;
            } catch (ConnectionException $e) {
                echo sprintf("Unable connection to %s: %s\n", $port, $e->getMessage());
                $port++;
            }
        }

        $this->connection->write(json_encode(['cmd' => 'register', 'pid' => getmypid(), 'port' => $port]));
    }

    public function onRequest(Request $request, Response $response)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->onRequest($request, $response);
        } else {
            $response->writeHead('404');
            $response->end('No Bridge Defined.');
        }
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

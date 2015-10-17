<?php

namespace PHPPM;

use React\EventLoop\Factory;
use React\Socket\Connection;

class Client
{
    /**
     * @var int
     */
    protected $controllerPort;

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var Connection
     */
    protected $connection;

    public function __construct($controllerPort = 5500)
    {
        $this->controllerPort = $controllerPort;
        $this->loop = Factory::create();
    }

    /**
     * @return Connection
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }
        $client = stream_socket_client('tcp://127.0.0.1:'.$this->controllerPort);
        $this->connection = new Connection($client, $this->loop);

        return $this->connection;
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;
        $connection = $this->getConnection();

        $loop = $this->loop;

        $connection->on('data', function ($data) use ($callback) {
            $callback($data);
        });

        $connection->on('end', function () use ($loop) {
            $loop->stop();
        });
        $connection->on('error', function () use ($loop) {
            $loop->stop();
        });

        $connection->write(json_encode($data));

        $this->loop->run();
    }

    public function getStatus(callable $callback)
    {
        $this->request('status', [], function ($result) use ($callback) {
            $callback(json_decode($result, true));
        });
    }

    public function restart(callable $callback)
    {
        $this->request('restart', [], $callback);
    }
}

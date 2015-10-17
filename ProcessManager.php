<?php

namespace PHPPM;

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

    public function __construct($port = 8080, $host = '127.0.0.1', $slaveCount = 8)
    {
        $this->slaveCount = $slaveCount;
        $this->host = $host;
        $this->port = $port;
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
        $this->controller->listen(5500);
        $http = new \React\Http\Server($this->controller);
        $http->on('request', \Closure::bind(function (Request $request, Response $response) {
            $response->writeHead();
            $response->end(json_encode($this->status(), JSON_PRETTY_PRINT));
        }, $this));

        $this->web = new Server($this->loop);
        $this->web->on('connection', [$this, 'onWeb']);
        $this->web->listen($this->port, $this->host);

        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newInstance();
        }

        $this->run = true;
        $this->loop();
    }

    public function onWeb(Connection $incoming)
    {
        do {
            $slaves = array_values($this->slaves);
            $slaveId = $this->getNextSlave();
        } while (!array_key_exists($slaveId, $slaves));
        $port = $slaves[$slaveId]['port'];
        $client = stream_socket_client('tcp://127.0.0.1:'.$port);
        $redirect = new Stream($client, $this->loop);

        $redirect->on(
            'close',
            function () use ($incoming) {
                $incoming->end();
            }
        );

        $incoming->on(
            'data',
            function ($data) use ($redirect) {
                $redirect->write($data);
            }
        );

        $redirect->on(
            'data',
            function ($data) use ($incoming) {
                $incoming->write($data);
            }
        );
    }

    /**
     * @return integer
     */
    protected function getNextSlave()
    {
        $count = count($this->slaves);

        $this->index++;
        if ($count >= $this->index) {
            //end
            $this->index = 0;
        }

        return $this->index;
    }

    public function onSlaveConnection(Connection $conn)
    {
        $conn->on(
            'data',
            \Closure::bind(
                function ($data) use ($conn) {
                    $this->onData($data, $conn);
                },
                $this
            )
        );
        $conn->on(
            'close',
            \Closure::bind(
                function () use ($conn) {
                    foreach ($this->slaves as $idx => $slave) {
                        if ($slave['connection'] === $conn) {
                            $this->removeSlave($idx);
                            $this->checkSlaves();
                            pcntl_waitpid($slave['pid'], $pidStatus, WNOHANG);
                        }
                    }
                },
                $this
            )
        );
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
        $result['port'] = 5000;
        $result['slaves'] = array_map(function ($slave) {
            return array_diff_key($slave, ['connection' => null]);
        }, $this->slaves);

        return $result;
    }

    protected function commandRestart(array $data, Connection $conn)
    {
        $conn->end('not supported');
    }

    protected function commandStatus(array $data, Connection $conn)
    {
        $response = json_encode($this->status());
        printf("%s\n", $response);
        $conn->end($response);
    }

    protected function commandPing(array $data, Connection $conn)
    {
        foreach ($this->slaves as &$slave) {
            if ($slave['pid'] === $data['pid']) {
                $slave['memory'] = $data['memory'];
                break;
            }
        }
    }

    protected function commandRegister(array $data, Connection $conn)
    {
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];
        $this->slaves[] = [
            'pid'        => $pid,
            'port'       => $port,
            'connection' => $conn,
        ];
        if ($this->waitForSlaves && $this->slaveCount === count($this->slaves)) {
            $slaves = [];
            foreach ($this->slaves as $slave) {
                $slaves[] = $slave['port'];
            }
            echo sprintf("%d slaves (%s) up and ready.\n", $this->slaveCount, implode(', ', $slaves));
        }
    }

    protected function commandUnregister(array $data)
    {
        $pid = (int)$data['pid'];
        echo sprintf("Slave died. (pid %d)\n", $pid);
        foreach ($this->slaves as $idx => $slave) {
            if ($slave['pid'] === $pid) {
                $this->removeSlave($idx);
                $this->checkSlaves();
            }
        }
        $this->checkSlaves();
    }

    protected function removeSlave($idx)
    {
        $slave = $this->slaves[$idx];
        echo sprintf("Die slave %s on port %s\n", $slave['pid'], $slave['port']);
        $slave['connection']->close();
        unset($this->slaves[$idx]);
    }

    protected function checkSlaves()
    {
        if (!$this->run) {
            return;
        }

        $i = count($this->slaves);
        if ($this->slaveCount !== $i) {
            echo sprintf('Boot %d new slaves ... ', $this->slaveCount - $i);
            $this->waitForSlaves = true;
            for (; $i < $this->slaveCount; $i++) {
                $this->newInstance();
            }
        }
    }

    private function loop()
    {
        $this->loop->run();
    }

    private function newInstance()
    {
        $pid = pcntl_fork();
        if (!$pid) {
            echo 'fork'.PHP_EOL;
//            try {
            //we're in the slave now
            new ProcessSlave($this->getBridge(), $this->appBootstrap, $this->appenv);
//            } catch (\Exception $e) {
//                echo $e->getMessage().PHP_EOL;
//            }
            exit;
        }
    }
}

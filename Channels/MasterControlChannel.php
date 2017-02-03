<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:46 PM
 */

namespace PHPPM\Channels;

use Closure;
use PHPPM\ProcessManager;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\Server;

class MasterControlChannel
{
    /**
     * @var ProcessManager
     */
    private $manager;

    public function __construct(ProcessManager $manager, LoopInterface $loop)
    {
        $this->manager = $manager;

        $controller = new Server($loop);
        $controller->on('connection', [$this, 'onSlaveConnection']);
        $controller->listen($manager->config->port, $manager->config->host);

        $http = new \PHPPM\Server($controller);
        /** @noinspection PhpUnusedParameterInspection */
        $http->on('request', Closure::bind(function (Request $request, Response $response) use ($manager) {
            $response->writeHead();
            $response->end($manager->clusterStatusAsJson());
        }, $this));
    }

    public function onSlaveConnection(Connection $conn)
    {
        $conn->on('data', Closure::bind(function ($data) use ($conn) {
            $this->manager->processControlCommand($data, $conn);
        }, $this));
        $conn->on('close', Closure::bind(function () use ($conn) {
            foreach ($this->manager->getSlaves() as $idx => $slave) {
                if ($slave->equalsByConnection($conn)) {
                    $this->manager->removeSlave($idx);
                }
            }
        }, $this));
    }
}

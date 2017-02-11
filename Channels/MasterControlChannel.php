<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:46 PM
 */

namespace PHPPM\Channels;

use Evenement\EventEmitter;
use PHPPM\Bus;
use PHPPM\Control\Commands\LogCommand;
use PHPPM\Control\Commands\NewMasterCommand;
use PHPPM\Control\Commands\NewWorkerCommand;
use PHPPM\Control\Commands\PingCommand;
use PHPPM\Control\Commands\PrepareMasterCommand;
use PHPPM\Control\Commands\RegisterCommand;
use PHPPM\Control\Commands\RestartCommand;
use PHPPM\Control\Commands\ShutdownCommand;
use PHPPM\Control\Commands\StatusCommand;
use PHPPM\Control\Commands\StopCommand;
use PHPPM\Control\Commands\UnregisterCommand;
use PHPPM\Libs\NetUtils;
use PHPPM\ProcessManager;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\Server;

class MasterControlChannel extends EventEmitter
{
    /**
     * @var ProcessManager
     */
    private $manager;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var bool
     */
    private $prepareMaster = false;

    public function __construct(ProcessManager $manager, LoopInterface $loop)
    {
        $this->manager = $manager;
        $this->loop    = $loop;
    }

    public function run()
    {
        if (NetUtils::portIsClose($this->manager->getConfig()->port, $this->manager->getConfig()->host)) {
            $this->runControlBus();

            $this->runWorkerBus();

            $this->emit('done');

            return;
        }

        $this->manager->getLogger()->info('Stop old master and start new master.');

        $connection = stream_socket_client(sprintf('tcp://%s:%s', $this->manager->getConfig()->host, $this->manager->getConfig()->port));
        $connection = new Connection($connection, $this->loop);
        $bus        = new Bus($connection, $this->manager);
        $bus->def(NewWorkerCommand::class);
        $bus->on(PrepareMasterCommand::class, function () {
            $this->prepareMaster = true;
        });
        $bus->def(LogCommand::class);

        $connection->on('close', function () {
            if ($this->prepareMaster) {
                $this->loop->addTimer(4, function () {
                    $this->manager->getLogger()->info('Old cluster shutdown. Run control bus and start cluster.');

                    $this->runControlBus();

                    $this->emit('done');
                });
            } else {
                $this->manager->getLogger()->err('Old master don\'t send prepare command.');

                exit;
            }
        });

        $this->on('workers_bus', function () use ($bus) {
            $bus->start();

            $bus->send(NewMasterCommand::build(getmypid()));
        });

        $this->runWorkerBus();
    }

    private function runControlBus()
    {
        $controlServer = new Server($this->loop);
        $controlServer->on('connection', function (Connection $connection) {
            $bus = new Bus($connection, $this->manager);
            $bus->def(NewMasterCommand::class);
            $bus->def(StatusCommand::class);
            $bus->def(ShutdownCommand::class);
            $bus->def(StopCommand::class);
            $bus->def(RestartCommand::class);
            $bus->start();
        });

        $controlServer->listen($this->manager->getConfig()->port, $this->manager->getConfig()->host);

        $http = new \PHPPM\Server($controlServer);
        /** @noinspection PhpUnusedParameterInspection */
        $http->on('request', function (Request $request, Response $response) {
            $response->writeHead();
            $response->end($this->manager->clusterStatusAsJson());
        });
    }

    private function runWorkerBus()
    {
        $workersServer = new Server($this->loop);
        $workersServer->on('connection', function (Connection $connection) {
            $bus = new Bus($connection, $this->manager);
            $bus->def(RegisterCommand::class);
            $bus->def(UnregisterCommand::class);
            $bus->def(PingCommand::class);

            $connection->on('close', function () use ($connection) {
                foreach ($this->manager->workers()->all() as $worker) {
                    if ($worker->equalsByConnection($connection)) {
                        $this->manager->removeWorker($worker);
                    }
                }
            });

            $bus->start();
        });

        $config = $this->manager->getConfig();

        $config->workers_control_port = $config->port - 1;

        for ($i = 5; $i > 0; $i--) {
            if (NetUtils::portIsClose($config->workers_control_port, $config->host)) {
                $workersServer->listen($config->workers_control_port, $config->host);

                $this->manager->getLogger()->info(sprintf('Use workers_control_port: %d', $config->workers_control_port));

                $this->emit('workers_bus');

                break;
            }

            $config->workers_control_port--;
        }
    }
}

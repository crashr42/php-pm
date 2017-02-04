<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:46 PM
 */

namespace PHPPM\Channels;

use Closure;
use Evenement\EventEmitter;
use PHPPM\Bus;
use PHPPM\Control\Commands\LogCommand;
use PHPPM\Control\Commands\NewMasterCommand;
use PHPPM\Control\Commands\NewSlaveCommand;
use PHPPM\Control\Commands\PingCommand;
use PHPPM\Control\Commands\PrepareMasterCommand;
use PHPPM\Control\Commands\RegisterCommand;
use PHPPM\Control\Commands\RestartCommand;
use PHPPM\Control\Commands\ShutdownCommand;
use PHPPM\Control\Commands\StatusCommand;
use PHPPM\Control\Commands\StopCommand;
use PHPPM\Control\Commands\UnregisterCommand;
use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\EventLoop\LoopInterface;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Connection;
use React\Socket\ConnectionException;
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

    /**
     * @var Closure
     */
    private $defaultHandler;

    public function __construct(ProcessManager $manager, LoopInterface $loop)
    {
        $this->manager = $manager;
        $this->loop    = $loop;

        $this->defaultHandler = function (ControlCommand $command, Connection $connection, ProcessManager $manager) {
            $command->handleOnMaster($connection, $manager);
        };
    }

    public function run()
    {
        try {
            $this->runControlBus();

            $this->runSlaveBus();

            $this->emit('done');
        } catch (ConnectionException $e) {
            $connection = stream_socket_client(sprintf('tcp://%s:%s', $this->manager->config->host, $this->manager->config->port));
            $connection = new Connection($connection, $this->loop);
            $bus        = new Bus($connection, $this->manager);
            $bus->on(NewSlaveCommand::class, $this->defaultHandler);
            $bus->on(PrepareMasterCommand::class, function () {
                $this->prepareMaster = true;
            });
            $bus->on(LogCommand::class, $this->defaultHandler);

            $connection->on('close', function () {
                if ($this->prepareMaster) {
                    $this->runControlBus();

                    $this->emit('done');
                } else {
                    exit;
                }
            });

            $this->on('slave_bus', function () use ($bus) {
                $bus->run();

                $bus->send((new NewMasterCommand())->serialize());
            });

            $this->runSlaveBus();
        }
    }

    private function runControlBus()
    {
        $controlBus = new Server($this->loop);
        $controlBus->on('connection', function (Connection $connection) {
            $bus = new Bus($connection, $this->manager);
            $bus->on(NewMasterCommand::class, $this->defaultHandler);
            $bus->on(StatusCommand::class, $this->defaultHandler);
            $bus->on(ShutdownCommand::class, $this->defaultHandler);
            $bus->on(StopCommand::class, $this->defaultHandler);
            $bus->on(RestartCommand::class, $this->defaultHandler);
            $bus->run();
        });
        $controlBus->listen($this->manager->config->port, $this->manager->config->host);

        $http = new \PHPPM\Server($controlBus);
        /** @noinspection PhpUnusedParameterInspection */
        $http->on('request', function (Request $request, Response $response) {
            $response->writeHead();
            $response->end($this->manager->clusterStatusAsJson());
        });
    }

    private function runSlaveBus()
    {
        $slaveBus = new Server($this->loop);
        $slaveBus->on('connection', function (Connection $connection) {
            $bus = new Bus($connection, $this->manager);
            $bus->on(RegisterCommand::class, $this->defaultHandler);
            $bus->on(UnregisterCommand::class, $this->defaultHandler);
            $bus->on(PingCommand::class, $this->defaultHandler);

            $connection->on('close', function () use ($connection) {
                foreach ($this->manager->slavesCollection()->getSlaves() as $slave) {
                    if ($slave->equalsByConnection($connection)) {
                        $this->manager->slavesCollection()->removeSlave($slave);
                    }
                }
            });

            $bus->run();
        });
        $this->manager->config->slaves_control_port = $this->manager->config->port - 1;

        for ($i = 5; $i > 0; $i--) {
            try {
                $slaveBus->listen($this->manager->config->slaves_control_port, $this->manager->config->host);

                $this->emit('slave_bus');

                break;
            } catch (ConnectionException $e) {
                $this->manager->config->slaves_control_port--;
            }
        }
    }
}

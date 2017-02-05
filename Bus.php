<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 11:23 AM
 */

namespace PHPPM;

use Evenement\EventEmitter;
use PHPPM\Control\ControlCommand;
use React\Socket\Connection;

class Bus extends EventEmitter
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ProcessManager|ProcessWorker
     */
    private $manager;

    /**
     * @var \Closure
     */
    private $defaultHandler;

    /**
     * Bus constructor.
     * @param Connection $connection
     * @param ProcessManager|ProcessWorker $manager
     */
    public function __construct(Connection $connection, $manager)
    {
        $this->connection = $connection;
        $this->manager    = $manager;

        $this->defaultHandler = function (ControlCommand $command, Connection $connection, $manager) {
            $command->handle($connection, $manager);
        };
    }

    /**
     * Handle commands.
     */
    public function run()
    {
        $this->connection->on('data', function ($raw) {
            $this->manager->getLogger()->debug($raw);

            $rawCommands = explode("\n", $raw);
            foreach ($rawCommands as $rawCommand) {
                if (trim($rawCommand) === '') {
                    continue;
                }

                $this->manager->getLogger()->debug("Raw command: {$rawCommand}");

                if ($command = ControlCommand::find($rawCommand)) {
                    $this->manager->getLogger()->debug('Run command: '.get_class($command));

                    $this->emit(get_class($command), [$command, $this->connection, $this->manager]);
                }
            }
        });
    }

    /**
     * Send command.
     *
     * @param string $cmd
     * @return bool|void
     */
    public function send($cmd)
    {
        $this->manager->getLogger()->debug("Send: {$cmd}");

        return $this->connection->write(sprintf("%s\n", $cmd));
    }

    /**
     * Stop handle command and close connections.
     */
    public function end()
    {
        $this->connection->close();
    }

    /**
     * Check connection is alive.
     *
     * @return bool
     */
    public function isAlive()
    {
        return $this->connection->isWritable();
    }

    /**
     * Check bus is die.
     *
     * @return bool
     */
    public function isDie()
    {
        return !$this->isAlive();
    }

    /**
     * Define default command handler.
     *
     * @param string $commandClass
     */
    public function def($commandClass)
    {
        $this->on($commandClass, $this->defaultHandler);
    }
}

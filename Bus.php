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
     * @var ProcessManager|ProcessSlave
     */
    private $manager;

    /**
     * Bus constructor.
     * @param Connection $connection
     * @param ProcessManager|ProcessSlave $manager
     */
    public function __construct(Connection $connection, $manager)
    {
        $this->connection = $connection;
        $this->manager    = $manager;
    }

    public function run()
    {
        $this->connection->on('data', function ($raw) {
            $this->manager->logger->debug($raw);

            $rawCommands = explode("\n", $raw);
            foreach ($rawCommands as $rawCommand) {
                if (trim($rawCommand) === '') {
                    continue;
                }

                $this->manager->logger->debug("Raw command: {$rawCommand}");

                if ($command = ControlCommand::find($rawCommand)) {
                    $this->manager->logger->debug('Run command: '.get_class($command));

                    $this->emit(get_class($command), [$command, $this->connection, $this->manager]);
                }
            }
        });
    }

    public function send($cmd)
    {
        $this->manager->logger->debug("Send: {$cmd}");

        return $this->connection->write(sprintf("%s\n", $cmd));
    }

    public function end()
    {
        $this->connection->close();
    }
}

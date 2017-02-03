<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:38 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class RestartCommand extends ControlCommand
{
    public function handle($data, Connection $connection, ProcessManager $manager)
    {
        if ($manager->shutdownLock) {
            $manager->logger->warning('Cluster shutdown already in progress.');
            $connection->write('Cluster shutdown already in progress.');
            $connection->end();

            return;
        }

        $manager->shutdownLock = true;

        $slaves = $manager->getSlaves();

        $manager->gracefulShutdown(array_pop($slaves), $slaves, $connection);
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'restart']);
    }
}

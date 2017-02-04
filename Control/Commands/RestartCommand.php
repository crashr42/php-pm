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
    public function handle(Connection $connection, ProcessManager $manager)
    {
        if ($manager->shutdownLock) {
            $manager->getLogger()->warning('Cluster shutdown already in progress.');
            $connection->write('Cluster shutdown already in progress.');
            $connection->end();

            return;
        }

        $manager->shutdownLock = true;

        $workers = $manager->workersCollection()->all();

        $manager->gracefulShutdown(array_pop($workers), $workers, $connection);
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'restart']);
    }
}

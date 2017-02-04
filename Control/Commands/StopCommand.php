<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:41 PM
 */

namespace PHPPM\Control\Commands;

use Closure;
use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class StopCommand extends ControlCommand
{
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        if ($manager->shutdownLock) {
            $manager->getLogger()->warning('Cluster stop in progress ...');
            $connection->write('Cluster stop in progress ...');
            $connection->end();

            return;
        }

        $manager->allowNewInstances = false;
        $manager->shutdownLock      = true;

        $workers = $manager->workersCollection()->all();

        $manager->gracefulShutdown(array_pop($workers), $workers, $connection, Closure::bind(function () use ($manager) {
            $manager->getLogger()->info('Cluster stopped.');
            $manager->getLoop()->stop();
            exit;
        }, $this));
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'stop']);
    }
}

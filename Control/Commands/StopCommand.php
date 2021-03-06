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
    public function handle(Connection $connection, ProcessManager $manager)
    {
        if ($manager->inShutdownLock()) {
            $manager->getLogger()->warning('Cluster stop in progress ...');
            $connection->write('Cluster stop in progress ...');
            $connection->end();

            return;
        }

        $manager->setAllowNewInstances(false);
        $manager->setShutdownLock(true);

        $workers = $manager->workers()->all();

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

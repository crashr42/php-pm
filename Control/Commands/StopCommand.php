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
    public function handle($data, Connection $connection, ProcessManager $manager)
    {
        if ($manager->shutdownLock) {
            $manager->logger->warning('Cluster stop in progress ...');
            $connection->write('Cluster stop in progress ...');
            $connection->end();

            return;
        }

        $manager->allowNewInstances = false;
        $manager->shutdownLock      = true;

        $slaves = $manager->getSlaves();

        $manager->gracefulShutdown(array_pop($slaves), $slaves, $connection, Closure::bind(function () use ($manager) {
            $manager->logger->info('Cluster stopped.');
            $manager->loop->stop();
            exit;
        }, $this));
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'stop']);
    }
}

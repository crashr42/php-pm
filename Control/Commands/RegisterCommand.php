<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:32 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use PHPPM\Worker;
use React\Socket\Connection;

class RegisterCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $manager->waitedWorkers--;

        if (count($manager->workersCollection()) === $manager->getConfig()->workers) {
            $manager->getLogger()->warning('All workers already spawned. Reject worker.');

            $connection->end();

            return;
        }

        $newWorker = new Worker();
        $newWorker->setPid($this->data['pid']);
        $newWorker->setPort($this->data['port']);
        $newWorker->setHost($manager->getConfig()->host);
        $newWorker->setConnection($connection);
        $newWorker->setPingAt(date('Y-m-d H:i:s O'));
        $newWorker->setBornAt(date('Y-m-d H:i:s O'));

        $isNew = count(array_filter($manager->workersCollection()->all(), function ($worker) use ($newWorker) {
                /** @var Worker $worker */
                return $newWorker->equals($worker);
            })) === 0;

        if ($isNew) {
            $manager->getLogger()->info(sprintf('New worker %s up and ready.', $newWorker->getPort()));
            $manager->workersCollection()->addWorker($newWorker);
        }
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'register', 'pid' => func_get_arg(0), 'port' => func_get_arg(1)]);
    }
}

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
use PHPPM\Slave;
use React\Socket\Connection;

class RegisterCommand extends ControlCommand
{
    public function handle($data, Connection $connection, ProcessManager $manager)
    {
        $manager->waitSlaves--;

        if (count($manager->getSlaves()) === $manager->slavesCount) {
            $manager->logger->warning('All slaves already spawned. Reject slave.');

            $connection->end();

            return;
        }

        $newSlave = new Slave();
        $newSlave->setPid($data['pid']);
        $newSlave->setPort($data['port']);
        $newSlave->setHost($manager->host);
        $newSlave->setConnection($connection);
        $newSlave->setPingAt(date('Y-m-d H:i:s O'));
        $newSlave->setBornAt(date('Y-m-d H:i:s O'));

        $isNew = count(array_filter($manager->getSlaves(), function ($slave) use ($newSlave) {
                /** @var Slave $slave */
                return $newSlave->equals($slave);
            })) === 0;

        if ($isNew) {
            $manager->logger->info(sprintf("New slave %s up and ready.\n", $newSlave->getPort()));
            $manager->addSlave($newSlave);
        }
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'register']);
    }
}

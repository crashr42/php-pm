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
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $manager->waitedSlaves--;

        if (count($manager->slavesCollection()) === $manager->config->workers) {
            $manager->logger->warning('All slaves already spawned. Reject slave.');

            $connection->end();

            return;
        }

        $newSlave = new Slave();
        $newSlave->setPid($this->data['pid']);
        $newSlave->setPort($this->data['port']);
        $newSlave->setHost($manager->config->host);
        $newSlave->setConnection($connection);
        $newSlave->setPingAt(date('Y-m-d H:i:s O'));
        $newSlave->setBornAt(date('Y-m-d H:i:s O'));

        $isNew = count(array_filter($manager->slavesCollection()->getSlaves(), function ($slave) use ($newSlave) {
                /** @var Slave $slave */
                return $newSlave->equals($slave);
            })) === 0;

        if ($isNew) {
            $manager->logger->info(sprintf('New slave %s up and ready.', $newSlave->getPort()));
            $manager->slavesCollection()->addSlave($newSlave);
        }
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'register', 'pid' => func_get_arg(0), 'port' => func_get_arg(1)]);
    }
}

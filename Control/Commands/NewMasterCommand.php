<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 12:40 AM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Bus;
use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use PHPPM\Slave;
use React\Socket\Connection;

class NewMasterCommand extends ControlCommand
{
    /**
     * @param Connection $connection
     * @param ProcessManager $manager
     */
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $bus = new Bus($connection, $manager);

        if ($manager->shutdownLock) {
            $bus->send((new LogCommand())->serialize('Master in shutdown mode.'));

            $connection->end();

            return;
        }

        $manager->shutdownLock = true;

        $slaves = $manager->slavesCollection()->getSlaves();

        $this->gracefulShutdown($bus, $manager, array_shift($slaves), $slaves);
    }

    private function gracefulShutdown(Bus $bus, ProcessManager $manager, Slave $slave, $slaves)
    {
        $slave->setStatus(Slave::STATUS_SHUTDOWN);

        /** @var Connection $connection */
        $slave->getConnection()->on('close', function () use ($bus, $manager, $slave, $slaves) {
            $manager->slavesCollection()->removeSlave($slave);

            $bus->send((new NewSlaveCommand())->serialize($slave->getPort()));

            $message = sprintf('Shutdown http://%s:%s', $slave->getHost(), $slave->getPort());
            $manager->logger->info($message);
            $bus->send((new LogCommand())->serialize($message));

            if (count($slaves) > 0) {
                $this->gracefulShutdown($bus, $manager, array_shift($slaves), $slaves);
            } else {
                $bus->send((new LogCommand())->serialize('Last worker shutdown.'));
                $bus->send((new PrepareMasterCommand())->serialize());

                $manager->loop->addTimer(2, function () use ($bus, $manager) {
                    $manager->loop->stop();

                    exit;
                });
            }
        });
        $bus->send((new LogCommand())->serialize(sprintf("Try shutdown http://%s:%s\n", $slave->getHost(), $slave->getPort())));

        $slave->getConnection()->write((new ShutdownCommand())->serialize());
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'newMaster']);
    }
}

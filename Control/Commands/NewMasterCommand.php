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
use PHPPM\Worker;
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

        $workers = $manager->workersCollection()->all();

        $this->gracefulShutdown($bus, $manager, array_shift($workers), $workers);
    }

    private function gracefulShutdown(Bus $bus, ProcessManager $manager, Worker $worker, $workers)
    {
        $worker->setStatus(Worker::STATUS_SHUTDOWN);

        /** @var Connection $connection */
        $worker->getConnection()->on('close', function () use ($bus, $manager, $worker, $workers) {
            if ($bus->isDie()) {
                $manager->shutdownLock = false;

                $manager->getLogger()->error('New master connection is die. Revert current master state.');

                $bus->end();

                return;
            }

            $manager->workersCollection()->removeWorker($worker);

            $bus->send((new NewWorkerCommand())->serialize($worker->getPort()));

            $message = sprintf('Shutdown http://%s:%s', $worker->getHost(), $worker->getPort());
            $manager->getLogger()->info($message);
            $bus->send((new LogCommand())->serialize($message));

            if (count($workers) > 0) {
                $this->gracefulShutdown($bus, $manager, array_shift($workers), $workers);
            } else {
                $bus->send((new LogCommand())->serialize('Last worker shutdown.'));
                $bus->send((new PrepareMasterCommand())->serialize());

                $manager->getLoop()->addTimer(2, function () use ($bus, $manager) {
                    $manager->getLoop()->stop();
                });
            }
        });
        $bus->send((new LogCommand())->serialize(sprintf("Try shutdown http://%s:%s\n", $worker->getHost(), $worker->getPort())));

        $worker->getConnection()->write((new ShutdownCommand())->serialize());
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'newMaster']);
    }
}

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
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $bus = new Bus($connection, $manager);

        if ($manager->shutdownLock) {
            $bus->send(LogCommand::build('Master in shutdown mode.'));

            $connection->end();

            return;
        }

        $manager->shutdownLock = true;

        $workers = $manager->workersCollection()->all();

        $this->gracefulShutdown($bus, $manager, array_shift($workers), $workers);
    }

    private function gracefulShutdown(Bus $bus, ProcessManager $manager, Worker $worker, $workers)
    {
        $bus->send(LogCommand::build(sprintf('Workers to restart %d', count($workers))));

        $worker->setStatus(Worker::STATUS_SHUTDOWN);

        if (!$worker->getConnection()->isWritable()) {
            $manager->getLogger()->warning("Worker at port {$worker->getPort()} already shutdown!");

            $this->onWorkerShutdown($bus, $manager, $worker, $workers);

            return;
        }

        /** @var Connection $connection */
        $worker->getConnection()->on('close', function () use ($bus, $manager, $worker, $workers) {
            $this->onWorkerShutdown($bus, $manager, $worker, $workers);
        });
        $bus->send(LogCommand::build(sprintf("Try shutdown http://%s:%s\n", $worker->getHost(), $worker->getPort())));

        $worker->getConnection()->write(ShutdownCommand::build());
    }

    private function onWorkerShutdown(Bus $bus, ProcessManager $manager, Worker $worker, $workers)
    {
        if ($bus->isDie()) {
            $manager->shutdownLock = false;

            $manager->getLogger()->error('New master connection is die. Revert current master state.');

            $bus->end();

            return;
        }

        $manager->workersCollection()->removeWorker($worker);

        $bus->send(NewWorkerCommand::build($worker->getPort()));

        $message = sprintf('Shutdown http://%s:%s', $worker->getHost(), $worker->getPort());
        $manager->getLogger()->info($message);
        $bus->send(LogCommand::build($message));

        if (count($workers) > 0) {
            $this->gracefulShutdown($bus, $manager, array_shift($workers), $workers);
        } else {
            $bus->send(LogCommand::build('Last worker shutdown.'));
            $bus->send(PrepareMasterCommand::build());

            $manager->getLoop()->addTimer(2, function () use ($bus, $manager) {
                $manager->getLogger()->info(sprintf('Cluster migrate to new master at pid %s.', $this->data['pid']));

                $manager->getLoop()->stop();
            });
        }
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'newMaster', 'pid' => func_get_arg(0)]);
    }
}

<?php

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use PHPPM\Worker;
use React\Socket\Connection;

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:11 PM
 */
class PingCommand extends ControlCommand
{
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $workers = $manager->workersCollection();

        foreach ($workers->all() as $worker) {
            $status = $this->data['status'];

            if ($worker->equalsByPid($status['pid'])) {
                $worker->setMemory($status['memory']);
                $worker->setBornAt($status['born_at']);
                $worker->setPingAt($status['ping_at']);

                $cpuUsage = (int)shell_exec("ps -p {$worker->getPid()} -o %cpu | tail -n 1");
                $worker->setCpuUsage($cpuUsage);

                if ($manager->hasShutdownWorkers()) {
                    break;
                }
                if ($worker->getMemory() > $manager->getConfig()->worker_memory_limit) {
                    $manager->getLogger()->warning(sprintf(
                        "Worker memory %s of limit %s exceeded.\n", $worker->getMemory(), $manager->getConfig()->worker_memory_limit
                    ));
                    $worker->setStatus(Worker::STATUS_SHUTDOWN);
                    $worker->getConnection()->write(json_encode(['cmd' => 'shutdown']));

                    break;
                }

                $cpuLimit = 10;
                if ($cpuUsage > $cpuLimit) {
                    $manager->getLogger()->warning(sprintf("Worker cpu usage %s of limit %s exceeded.\n", $cpuLimit, $cpuUsage));
                    $worker->setStatus(Worker::STATUS_SHUTDOWN);
                    $worker->getConnection()->write(json_encode(['cmd' => 'shutdown']));

                    break;
                }
                break;
            }
        }
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'ping', 'status' => func_get_arg(0)]);
    }
}

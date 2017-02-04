<?php

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use PHPPM\Slave;
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
        $slaves = $manager->slavesCollection();

        foreach ($slaves->getSlaves() as $idx => &$slave) {
            $status = $this->data['status'];

            if ($slave->equalsByPid($status['pid'])) {
                $slave->setMemory($status['memory']);
                $slave->setBornAt($status['born_at']);
                $slave->setPingAt($status['ping_at']);

                $cpuUsage = (int)shell_exec("ps -p {$slave->getPid()} -o %cpu | tail -n 1");
                $slave->setCpuUsage($cpuUsage);

                if ($manager->hasShutdownSlaves()) {
                    break;
                }
                if ($slave->getMemory() > $manager->config->worker_memory_limit) {
                    $manager->logger->warning(sprintf(
                        "Worker memory %s of limit %s exceeded.\n", $slave->getMemory(), $manager->config->worker_memory_limit
                    ));
                    $slave->setStatus(Slave::STATUS_SHUTDOWN);
                    $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));

                    break;
                }

                $cpuLimit = 10;
                if ($cpuUsage > $cpuLimit) {
                    $manager->logger->warning(sprintf("Worker cpu usage %s of limit %s exceeded.\n", $cpuLimit, $cpuUsage));
                    $slave->setStatus(Slave::STATUS_SHUTDOWN);
                    $slave->getConnection()->write(json_encode(['cmd' => 'shutdown']));

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

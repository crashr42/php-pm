<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:36 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class UnregisterCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $pid = $this->data['pid'];
        $manager->getLogger()->warning(sprintf("Worker died. (pid %d)\n", $pid));
        foreach ($manager->workersCollection()->all() as $worker) {
            if ($worker->equalsByPid($pid)) {
                $manager->workersCollection()->removeWorker($worker);
            }
        }
        $manager->checkWorkers();
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'unregister', 'pid' => func_get_arg(0)]);
    }
}

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
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $pid = $this->data['pid'];
        $manager->getLogger()->warning(sprintf("Slave died. (pid %d)\n", $pid));
        foreach ($manager->slavesCollection()->getSlaves() as $slave) {
            if ($slave->equalsByPid($pid)) {
                $manager->slavesCollection()->removeSlave($slave);
            }
        }
        $manager->checkSlaves();
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'unregister', 'pid' => func_get_arg(0)]);
    }
}

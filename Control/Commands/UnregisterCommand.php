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
    public function handle($data, Connection $connection, ProcessManager $manager)
    {
        $pid = $data['pid'];
        $manager->logger->warning(sprintf("Slave died. (pid %d)\n", $pid));
        foreach ($manager->getSlaves() as $idx => $slave) {
            if ($slave->equalsByPid($pid)) {
                $manager->removeSlave($idx);
            }
        }
        $manager->checkSlaves();
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'unregister']);
    }
}

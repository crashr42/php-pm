<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 12:40 AM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class NewSlaveCommand extends ControlCommand
{
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $manager->forkSlave();
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'newSlave', 'port' => func_get_arg(0)]);
    }
}

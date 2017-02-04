<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 12:19 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class LogCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $manager->getLogger()->debug($this->data['message']);
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'log', 'message' => func_get_arg(0)]);
    }
}

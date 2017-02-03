<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:35 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class ShutdownCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        // nothing to do
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'shutdown']);
    }
}

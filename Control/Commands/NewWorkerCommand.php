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

class NewWorkerCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $manager->forkWorker();
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'newWorker', 'port' => func_get_arg(0)]);
    }
}

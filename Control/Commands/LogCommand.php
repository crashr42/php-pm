<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/4/17
 * Time: 12:19 PM
 */

namespace PHPPM\Control\Commands;

use Monolog\Logger;
use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class LogCommand extends ControlCommand
{
    public function handle(Connection $connection, ProcessManager $manager)
    {
        $level = array_get($this->data, 'level', Logger::DEBUG);
        $manager->getLogger()->addRecord((int)$level, $this->data['message']);
    }

    public function serialize()
    {
        return json_encode([
            'cmd'     => 'log',
            'message' => func_get_arg(0),
            'level'   => func_num_args() > 1 ? func_get_arg(1) : Logger::DEBUG,
        ]);
    }
}

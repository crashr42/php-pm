<?php

namespace PHPPM\Control;
use PHPPM\ProcessManager;
use React\Socket\Connection;

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:11 PM
 */
abstract class ControlCommand
{
    public abstract function handle($data, Connection $connection, ProcessManager $manager);

    public abstract function serialize();
}

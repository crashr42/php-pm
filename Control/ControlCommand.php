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
    protected $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public abstract function handle(Connection $connection, ProcessManager $manager);

    public abstract function serialize();

    /**
     * Find and create control command.
     *
     * @param string $raw
     * @return bool|ControlCommand
     */
    public static function find($raw)
    {
        $data = json_decode($raw, true);

        $commandClass = sprintf('PHPPM\\Control\\Commands\\%sCommand', ucfirst($data['cmd']));

        if (class_exists($commandClass)) {
            return new $commandClass($data);
        }

        return false;
    }
}

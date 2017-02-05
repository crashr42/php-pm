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
    /**
     * @var array
     */
    protected $data;

    /**
     * ControlCommand constructor.
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Handle command with master process.
     *
     * @param Connection $connection
     * @param ProcessManager $manager
     */
    public abstract function handle(Connection $connection, ProcessManager $manager);

    /**
     * Serialize command for send from bus.
     *
     * @return string
     */
    public abstract function serialize();

    /**
     * Find and create control command.
     *
     * @param string $raw
     * @return bool|ControlCommand
     */
    public static function find($raw)
    {
        $data = @json_decode($raw, true) ?: [];

        if (!array_key_exists('cmd', $data)) {
            return false;
        }

        $commandClass = sprintf('PHPPM\\Control\\Commands\\%sCommand', ucfirst($data['cmd']));

        if (class_exists($commandClass)) {
            return new $commandClass($data);
        }

        return false;
    }

    /**
     * Build command with arguments and serialize it for send from bus.
     *
     * @return string
     */
    public static function build()
    {
        return call_user_func_array([(new static), 'serialize'], func_get_args());
    }
}

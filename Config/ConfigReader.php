<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:45 PM
 */

namespace PHPPM\Config;

/**
 * Class ConfigReader
 * @package PHPPM\Config
 * @property string bridge
 * @property integer workers
 * @property integer worker_memory_limit
 * @property integer port
 * @property string host
 * @property string bootstrap
 * @property string log_file
 * @property integer request_timeout
 * @property string working_directory
 * @property string appenv
 */
class ConfigReader extends \ArrayObject
{
    public function __get($name)
    {
        return $this[$name];
    }

    public function __set($name, $value)
    {
        return $this[$name] = $value;
    }

    public function __isset($name)
    {
        return array_key_exists($name, $this);
    }
}
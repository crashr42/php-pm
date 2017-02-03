<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 9:20 PM
 */

namespace PHPPM;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;

class Logger
{
    /**
     * @var \Monolog\Logger
     */
    private static $logger;

    public static function get($class, $logFile)
    {
        if (static::$logger === null) {
            $lineFormatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%', null, true, true);

            static::$logger = new \Monolog\Logger($class);
            static::$logger->pushHandler(new StreamHandler($logFile));
            static::$logger->pushHandler((new ErrorLogHandler())->setFormatter($lineFormatter));
        }

        return static::$logger;
    }
}

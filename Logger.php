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
     * Initialize logger for given class.
     *
     * @param string $class
     * @param string $logFile
     * @param string $level
     * @return \Monolog\Logger
     */
    public static function get($class, $logFile, $level = 'debug')
    {
        $lineFormatter = new LineFormatter('[%datetime%] %channel%.%level_name%: %message% %context% %extra%', null, true, true);

        /** @var string|int $level */
        $level = \Monolog\Logger::toMonologLevel($level);

        $logger = new \Monolog\Logger($class);
        $logger->pushHandler(new StreamHandler($logFile, $level));
        $logger->pushHandler((new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level))->setFormatter($lineFormatter));

        return $logger;
    }
}

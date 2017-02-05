<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 9:20 PM
 */

namespace PHPPM\Log;

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
     * @param int $pid
     * @return \Monolog\Logger
     */
    public static function get($class, $logFile, $level = 'debug', $pid)
    {
        $lineFormatter = new LineFormatter($pid, '[%datetime%] %channel%.%level_name% [%pid]: %message% %context% %extra%', null, true, true);

        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        $level = \Monolog\Logger::toMonologLevel($level);

        $logger = new \Monolog\Logger($class);
        $logger->pushHandler(new StreamHandler($logFile, $level));
        $logger->pushHandler((new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level))->setFormatter($lineFormatter));

        return $logger;
    }
}

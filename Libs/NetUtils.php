<?php

namespace PHPPM\Libs;

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/11/17
 * Time: 6:31 PM
 */
class NetUtils
{
    /**
     * Check ethernet port is open.
     *
     * @param int $port
     * @param string $host
     * @return bool
     */
    public static function portIsOpen($port, $host = '0.0.0.0')
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$fp) {
            return false;
        }

        fclose($fp);

        return true;
    }

    /**
     * Check ethernet port is close.
     *
     * @param int $port
     * @param string $host
     * @return bool
     */
    public static function portIsClose($port, $host = '0.0.0.0')
    {
        return !static::portIsOpen($port, $host);
    }
}

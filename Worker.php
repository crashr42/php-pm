<?php

/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 04.12.15
 * Time: 20:14
 */

namespace PHPPM;

use Carbon\Carbon;
use React\Socket\Connection;

class Worker
{
    const STATUS_OK       = 'ok';
    const STATUS_SHUTDOWN = 'shutdown';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var int
     */
    protected $pid;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $status = self::STATUS_OK;

    /**
     * @var int
     */
    protected $memory;

    /**
     * @var string
     */
    protected $bornAt;

    /**
     * @var string
     */
    protected $pingAt;

    /**
     * @var int
     */
    protected $cpuUsage;

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param Connection $connection
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     */
    public function setPid($pid)
    {
        $this->pid = (int)$pid;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param string $host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param int $port
     */
    public function setPort($port)
    {
        $this->port = (int)$port;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * @return int
     */
    public function getMemory()
    {
        return $this->memory;
    }

    /**
     * @param int $memory
     */
    public function setMemory($memory)
    {
        $this->memory = (int)$memory;
    }

    /**
     * @return string
     */
    public function getBornAt()
    {
        return $this->bornAt;
    }

    /**
     * @param string $bornAt
     */
    public function setBornAt($bornAt)
    {
        $this->bornAt = $bornAt;
    }

    /**
     * @return string
     */
    public function getPingAt()
    {
        return $this->pingAt;
    }

    /**
     * @param string $pingAt
     */
    public function setPingAt($pingAt)
    {
        $this->pingAt = $pingAt;
    }

    /**
     * @return array
     */
    public function asJson()
    {
        return array_filter([
            'pid'         => $this->getPid(),
            'host'        => $this->getHost(),
            'port'        => $this->getPort(),
            'status'      => $this->getStatus(),
            'memory'      => $this->getMemory(),
            'cpu_percent' => $this->getCpuUsage(),
            'born_at'     => $this->getBornAt(),
            'ping_at'     => $this->getPingAt(),
            'uptime'      => Carbon::parse($this->getBornAt())->diff(Carbon::parse($this->getPingAt()))->format('%Y-%M-%D %H:%M:%S'),
        ], function ($value) {
            return $value !== null;
        });
    }

    /**
     * @param Worker $otherWorker
     *
     * @return bool
     */
    public function equals(Worker $otherWorker)
    {
        return $this->getPort() === $otherWorker->getPort();
    }

    /**
     * @param int|string $port
     *
     * @return bool
     */
    public function equalsByPort($port)
    {
        return $this->getPort() === (int)$port;
    }

    /**
     * @param int|string $pid
     *
     * @return bool
     */
    public function equalsByPid($pid)
    {
        return $this->getPid() === (int)$pid;
    }

    /**
     * @param Connection $connection
     *
     * @return bool
     */
    public function equalsByConnection(Connection $connection)
    {
        return $this->getConnection() === $connection;
    }

    /**
     * @param $cpuUsage
     */
    public function setCpuUsage($cpuUsage)
    {
        $this->cpuUsage = $cpuUsage;
    }

    /**
     * @return int
     */
    public function getCpuUsage()
    {
        return $this->cpuUsage;
    }
}

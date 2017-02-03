<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:59 PM
 */

namespace PHPPM;

class SlavesCollection implements \Countable
{
    /**
     * @var int
     */
    protected $index = 0;

    /**
     * @var Slave[]
     */
    private $slaves = [];

    /**
     * Get all slaves.
     *
     * @return Slave[]
     */
    public function getSlaves()
    {
        return $this->slaves;
    }

    /**
     * Add new slave.
     *
     * @param Slave $slave
     */
    public function addSlave(Slave $slave)
    {
        $this->slaves[] = $slave;
    }

    public function removeSlave(Slave $slave)
    {
        foreach ($this->slaves as $idx => $slv) {
            if ($slv->equals($slave)) {
                unset($this->slaves[$idx]);
            }
        }
    }

    /**
     * Returning active slaves.
     * @return array
     */
    public function activeSlaves()
    {
        return array_filter($this->slaves, function ($slave) {
            /** @var Slave $slave */
            return $slave->getStatus() === Slave::STATUS_OK;
        });
    }

    /**
     * @return integer
     */
    public function getNextSlave()
    {
        $count = count($this->activeSlaves());

        $this->index++;
        if ($count >= $this->index) {
            $this->index = 0;
        }

        return $this->index;
    }

    /**
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        return count($this->slaves);
    }

    /**
     * Has workers in shutdown status?
     *
     * @return bool
     */
    public function hasShutdownSlaves()
    {
        $hasShutdown = false;
        foreach ($this->slaves as $slave) {
            if ($slave->getStatus() === Slave::STATUS_SHUTDOWN) {
                $hasShutdown = true;
                break;
            }
        }

        return $hasShutdown;
    }
}

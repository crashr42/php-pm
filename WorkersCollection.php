<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:59 PM
 */

namespace PHPPM;

class WorkersCollection implements \Countable
{
    /**
     * @var int
     */
    protected $index = 0;

    /**
     * @var Worker[]
     */
    private $workers = [];

    /**
     * Get all workers.
     *
     * @return Worker[]
     */
    public function all()
    {
        return $this->workers;
    }

    /**
     * Add new worker.
     *
     * @param Worker $worker
     */
    public function addWorker(Worker $worker)
    {
        $this->workers[] = $worker;
    }

    public function removeWorker(Worker $worker)
    {
        foreach ($this->workers as $idx => $slv) {
            if ($slv->equals($worker)) {
                unset($this->workers[$idx]);
            }
        }
    }

    /**
     * Returning active workers.
     * @return array
     */
    public function activeWorkers()
    {
        return array_filter($this->workers, function ($worker) {
            /** @var Worker $worker */
            return $worker->getStatus() === Worker::STATUS_OK;
        });
    }

    /**
     * @return integer
     */
    public function getNextWorker()
    {
        $count = count($this->activeWorkers());

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
        return count($this->workers);
    }

    /**
     * Has workers in shutdown status?
     *
     * @return bool
     */
    public function hasShutdownWorkers()
    {
        $hasShutdown = false;
        foreach ($this->workers as $worker) {
            if ($worker->getStatus() === Worker::STATUS_SHUTDOWN) {
                $hasShutdown = true;
                break;
            }
        }

        return $hasShutdown;
    }
}

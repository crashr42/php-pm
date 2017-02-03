<?php
/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 10:51 PM
 */

namespace PHPPM\Channels;


use PHPPM\ProcessManager;
use PHPPM\Slave;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Socket\Server;
use React\Stream\Stream;

class BalancerControlChannel
{
    /**
     * @var ProcessManager
     */
    private $manager;
    /**
     * @var LoopInterface
     */
    private $loop;

    public function __construct(ProcessManager $manager, LoopInterface $loop)
    {
        $this->manager = $manager;
        $this->loop    = $loop;

        $this->web = new Server($loop);
        $this->web->on('connection', [$this, 'onWeb']);
        $this->web->listen($manager->config->port + 1, $manager->config->host);
    }

    /**
     * @param Connection $incoming
     */
    public function onWeb(Connection $incoming)
    {
        do {
            $slaves  = array_values($this->manager->slavesCollection()->activeSlaves());
            $slaveId = $this->manager->slavesCollection()->getNextSlave();
        } while (!array_key_exists($slaveId, $slaves));

        /** @var Slave $slave */
        $slave    = $slaves[$slaveId];
        $port     = $slave->getPort();
        $client   = stream_socket_client(sprintf('tcp://%s:%s', $this->manager->config->host, $port));
        $redirect = new Stream($client, $this->loop);

        $incoming->on('close', function () use ($redirect) {
            $redirect->end();
        });

        $incoming->on('error', function () use ($redirect) {
            $redirect->end();
        });

        $incoming->on('data', function ($data) use ($redirect) {
            $redirect->write($data);
        });

        $redirect->on('close', function () use ($incoming) {
            $incoming->end();
        });

        $redirect->on('error', function () use ($incoming) {
            $incoming->end();
        });

        $redirect->on('data', function ($data) use ($incoming) {
            $incoming->write($data);
        });
    }
}

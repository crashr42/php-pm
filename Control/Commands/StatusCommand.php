<?php

/**
 * Created by PhpStorm.
 * User: nikita.kem
 * Date: 2/3/17
 * Time: 8:22 PM
 */

namespace PHPPM\Control\Commands;

use PHPPM\Control\ControlCommand;
use PHPPM\ProcessManager;
use React\Socket\Connection;

class StatusCommand extends ControlCommand
{
    public function handleOnMaster(Connection $connection, ProcessManager $manager)
    {
        $response = $manager->clusterStatusAsJson();
        $manager->getLogger()->info(sprintf("Cluster status: %s\n", $response));
        $connection->end($response);
    }

    public function serialize()
    {
        return json_encode(['cmd' => 'status']);
    }
}

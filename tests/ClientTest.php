<?php

/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 17.10.15
 * Time: 17:46
 */
class ClientTest extends \TestCase
{
    public function test()
    {
        $client = new \PHPPM\Client();
        $client->getStatus(function ($data) {
            var_dump($data);
        });
    }
}

<?php

namespace PHPPM\Commands;

use PHPPM\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 19.10.15
 * Time: 22:37
 */
class StopCommand extends Command
{
    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('stop')
            ->addArgument('working-directory', null, 'working directory', './')
            ->setDescription('Stop of all processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handler = new Client();
        $handler->stop(function ($status) {
            echo json_encode($status).PHP_EOL;
        });
    }
}

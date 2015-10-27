<?php

namespace PHPPM\Commands;

use PHPPM\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
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
            ->addArgument('port', InputArgument::REQUIRED, 'Controller port')
            ->setDescription('Stop of all processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handler = new Client($input->getArgument('port'));
        $handler->stop(function ($status) {
            echo $status.PHP_EOL;
        });
    }
}

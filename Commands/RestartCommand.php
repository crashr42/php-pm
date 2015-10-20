<?php
/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 17.10.15
 * Time: 20:45
 */

namespace PHPPM\Commands;

use PHPPM\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RestartCommand extends Command
{
    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('restart')
            ->addArgument('port', InputArgument::REQUIRED, 'Controller port')
            ->setDescription('Restart of all processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handler = new Client($input->getArgument('port'));
        $handler->restart(function ($status) {
            echo json_encode($status).PHP_EOL;
        });
    }
}

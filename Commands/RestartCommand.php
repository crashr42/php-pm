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
            ->addArgument('working-directory', null, 'working directory', './')
            ->setDescription('Restart of all processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handler = new Client();
        $handler->restart(function ($status) {
            echo json_encode($status).PHP_EOL;
        });
    }
}

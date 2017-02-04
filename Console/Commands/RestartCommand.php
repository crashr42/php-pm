<?php

/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 17.10.15
 * Time: 20:45
 */

namespace PHPPM\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RestartCommand extends StartCommand
{
    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $this->setName('restart');
    }
}

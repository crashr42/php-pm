<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Created by PhpStorm.
 * User: nikita
 * Date: 22.10.15
 * Time: 22:29
 */
class HAProxyCommand extends Command
{
    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('haproxy')
            ->addArgument('working-directory', InputArgument::REQUIRED, 'The root of your appplication.')
            ->addOption('config', null, InputOption::VALUE_NONE, 'Generate haproxy config.')
            ->setDescription('Controlling haproxy.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        if (!file_exists($file = realpath($workingDir.'/ppm.json'))) {
            throw new \RuntimeException('Please create ppm.json in working directory.');
        }
        $config = json_decode(file_get_contents($file), true);

        if ($input->hasOption('config')) {
            ob_start();
            require __DIR__.'/templates/haproxy.conf.php';
            $content = ob_get_clean();
            echo $content;
        }
    }
}

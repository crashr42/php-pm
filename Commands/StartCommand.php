<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $config = [];
        if (file_exists($file = './ppm.json') || file_exists($file = dirname(realpath($GLOBALS['argv'][0])) . DIRECTORY_SEPARATOR . 'ppm.json')) {
             $config = json_decode(file_get_contents($file), true);
        }

        $bridge         = $this->defaultOrConfig($config, 'bridge', 'HttpKernel');
        $host           = $this->defaultOrConfig($config, 'host', '127.0.0.1');
        $port           = (int) $this->defaultOrConfig($config, 'port', 8080);
        $workers        = (int) $this->defaultOrConfig($config, 'workers', 8);
        $appenv         = $this->defaultOrConfig($config, 'app-env', 'dev');
        $appBootstrap   = $this->defaultOrConfig($config, 'bootstrap', 'PHPPM\Bootstraps\Symfony');
        $requestTimeout = $this->defaultOrConfig($config, 'request-timeout', null);

        $this
            ->setName('start')
            ->addArgument('working-directory', InputArgument::REQUIRED, 'The root of your application.')
            ->addOption('log-file', null, InputOption::VALUE_OPTIONAL, 'Log file.', './react.log')
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', $bridge)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer host. Default is 127.0.0.1', $host)
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', $port)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', $workers)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', $appenv)
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', $appBootstrap)
            ->addOption('worker-memory-limit', null, InputOption::VALUE_OPTIONAL, 'Memory limit per worker.', 25)
            ->addOption('request-timeout', null, InputOption::VALUE_OPTIONAL, 'Http request timeout is seconds.', $requestTimeout)
            ->setDescription('Starts the server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        $config = [];
        if (file_exists($file = realpath($workingDir.'/ppm.json'))) {
            echo sprintf("Use config file %s\n", $file).PHP_EOL;
            $config = json_decode(file_get_contents($file), true);
        }

        $bridge            = $this->defaultOrConfig($config, 'bridge', $input->getOption('bridge'));
        $host              = $this->defaultOrConfig($config, 'host', $input->getOption('host'));
        $port              = (int) $this->defaultOrConfig($config, 'port', $input->getOption('port'));
        $workers           = (int) $this->defaultOrConfig($config, 'workers', $input->getOption('workers'));
        $appenv            = $this->defaultOrConfig($config, 'app-env', $input->getOption('app-env'));
        $appBootstrap      = $this->defaultOrConfig($config, 'bootstrap', $input->getOption('bootstrap'));
        $logFile           = $this->defaultOrConfig($config, 'log-file', $input->getOption('log-file'));
        $workerMemoryLimit = $this->defaultOrConfig($config, 'worker-memory-limit', $input->getOption('worker-memory-limit'));
        $requestTimeout    = $this->defaultOrConfig($config, 'request-timeout', $input->getOption('request-timeout'));

        $handler = new ProcessManager($port, $host, $workers, $requestTimeout, $workerMemoryLimit, $logFile);

        $handler->setBridge($bridge);
        $handler->setAppEnv($appenv);
        $handler->setAppBootstrap($appBootstrap);
        $handler->setWorkingDirectory($workingDir);

        $handler->run();
    }

    private function defaultOrConfig($config, $name, $default) {
        $val = $default;

        if (array_key_exists($name, $config)) {
            $val = $config[$name];
        }

        return $val;
    }
}

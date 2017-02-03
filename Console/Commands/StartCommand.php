<?php

namespace PHPPM\Console\Commands;

use PHPPM\Config\ConfigReader;
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
        if (file_exists($file = './ppm.json') || file_exists($file = dirname(realpath($GLOBALS['argv'][0])).DIRECTORY_SEPARATOR.'ppm.json')) {
            $config = json_decode(file_get_contents($file), true);
        }

        $bridge         = $this->defaultOrConfig($config, 'bridge', 'HttpKernel');
        $host           = $this->defaultOrConfig($config, 'host', '127.0.0.1');
        $port           = (int)$this->defaultOrConfig($config, 'port', 8080);
        $workers        = (int)$this->defaultOrConfig($config, 'workers', 8);
        $appenv         = $this->defaultOrConfig($config, 'app_env', 'dev');
        $appBootstrap   = $this->defaultOrConfig($config, 'bootstrap', 'PHPPM\Bootstraps\Symfony');
        $requestTimeout = $this->defaultOrConfig($config, 'request_timeout', null);

        $this
            ->setName('start')
            ->addArgument('working-directory', InputArgument::REQUIRED, 'The root of your application.')
            ->addOption('log_file', null, InputOption::VALUE_OPTIONAL, 'Log file.', './react.log')
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', $bridge)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer host. Default is 127.0.0.1', $host)
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', $port)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', $workers)
            ->addOption('appenv', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', $appenv)
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', $appBootstrap)
            ->addOption('worker_memory_limit', null, InputOption::VALUE_OPTIONAL, 'Memory limit per worker.', 25)
            ->addOption('request_timeout', null, InputOption::VALUE_OPTIONAL, 'Http request timeout is seconds.', $requestTimeout)
            ->setDescription('Starts the server');
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

        $config['working_directory'] = $workingDir;

        $this->defaultOrConfig($config, 'bridge', $input->getOption('bridge'));
        $this->defaultOrConfig($config, 'host', $input->getOption('host'));
        $this->defaultOrConfig($config, 'port', $input->getOption('port'));
        $this->defaultOrConfig($config, 'workers', $input->getOption('workers'));
        $this->defaultOrConfig($config, 'appenv', $input->getOption('appenv'));
        $this->defaultOrConfig($config, 'bootstrap', $input->getOption('bootstrap'));
        $this->defaultOrConfig($config, 'log_file', $input->getOption('log_file'));
        $this->defaultOrConfig($config, 'worker_memory_limit', $input->getOption('worker_memory_limit'));
        $this->defaultOrConfig($config, 'request_timeout', $input->getOption('request_timeout'));

        $handler = new ProcessManager(new ConfigReader($config));

        $handler->run();
    }

    private function defaultOrConfig($config, $name, $default)
    {
        if (!array_key_exists($name, $config)) {
            $config[$name] = $default;
        }

        return $config[$name];
    }
}

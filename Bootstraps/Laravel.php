<?php

namespace PHPPM\Bootstraps;

use Stack\Builder;

/**
 * A default bootstrap for the Laravel framework
 */
class Laravel implements StackableBootstrapInterface
{
    /**
     * @var string|null The application environment
     */
    protected $appenv;

    /**
     * Store the application
     *
     * @var \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $app;

    /**
     * Instantiate the bootstrap, storing the $appenv
     * @param string|null $appenv The environment your application will use to bootstrap (if any)
     */
    public function __construct($appenv)
    {
        $this->appenv = $appenv;
    }

    /**
     * Create a Laravel application
     * @throws \RuntimeException
     */
    public function getApplication()
    {
        // Laravel 5 / Lumen
        if (file_exists('bootstrap/app.php')) {
            return $this->app = require_once 'bootstrap/app.php';
        }

        throw new \RuntimeException('Laravel bootstrap file not found');
    }

    /**
     * Return the StackPHP stack.
     * @param Builder $stack
     * @return Builder
     */
    public function getStack(Builder $stack)
    {
        return $stack;
    }
}

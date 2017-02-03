<?php

namespace PHPPM\Bootstraps;

use Stack\Builder;

/**
 * Stack\Builder extension for use with HttpKernel middlewares
 */
interface StackableBootstrapInterface extends BootstrapInterface
{
    /**
     * @param Builder $stack
     * @return Builder
     */
    public function getStack(Builder $stack);
}

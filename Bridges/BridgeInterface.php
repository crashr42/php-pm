<?php

namespace PHPPM\Bridges;

use React\Http\Request;
use React\Http\Response;

interface BridgeInterface
{
    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @param mixed $appenv
     * @return
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv);


    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param Request $request
     * @param Response $response
     */
    public function onRequest(Request $request, Response $response);
}

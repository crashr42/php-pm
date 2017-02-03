<?php

namespace PHPPM\Bridges;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request as LumenRequest;
use PHPPM\Bootstraps\BootstrapInterface;
use React\Http\Request as ReactRequest;
use React\Http\Response as ReactResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse as SymfonyStreamedResponse;

class HttpKernel implements BridgeInterface
{
    /**
     * An application implementing the HttpKernelInterface
     *
     * @var Application
     */
    protected $application;

    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * In the process of bootstrapping we decorate our application with any number of
     * *middlewares* using StackPHP's Stack\Builder.
     *
     * The app bootstraping itself is actually proxied off to an object implementing the
     * PHPPM\Bridges\BridgeInterface interface which should live within your app itself and
     * be able to be autoloaded.
     *
     * @param string $appBootstrap The name of the class used to bootstrap the application
     * @param string|null $appenv The environment your application will use to bootstrap (if any)
     * @see http://stackphp.com
     *
     * @throws \RuntimeException
     */
    public function bootstrap($appBootstrap, $appenv)
    {
        // include applications autoload
        $autoloader = dirname(realpath($_SERVER['SCRIPT_NAME'])).'/vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
        }

        $appBootstrap = $this->normalizeAppBootstrap($appBootstrap);

        $bootstrap = new $appBootstrap($appenv);

        if ($bootstrap instanceof BootstrapInterface) {
            $this->application = $bootstrap->getApplication();
        }
    }

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param \React\Http\Request $request
     * @param \React\Http\Response $response
     * @throws \UnexpectedValueException
     */
    public function onRequest(ReactRequest $request, ReactResponse $response)
    {
        if (null === $this->application) {
            return;
        }

        $content       = '';
        $headers       = $request->getHeaders();
        $contentLength = array_key_exists('Content-Length', $headers) ? (int)$headers['Content-Length'] : 0;

        $app = $this->application;
        $request->on('data', function ($data) use ($request, $response, &$content, $contentLength, &$app) {
            // read data (may be empty for GET request)
            $content .= $data;

            // handle request after receive
            if (strlen($content) >= $contentLength) {
                try {
                    $syRequest = self::mapRequest($request, $content);
                } catch (\Exception $exception) {
                    $response->writeHead(500);
                    $response->write($exception->getMessage());
                    $response->end();

                    return;
                }

                try {
                    $syResponse = $this->application->handle($syRequest);
                } catch (\Exception $exception) {
                    $response->writeHead(500);
                    $response->write($exception->getMessage());
                    $response->end();

                    return;
                }

                self::mapResponse($response, $syResponse);
            }
        });
    }

    /**
     * Convert React\Http\Request to Symfony\Component\HttpFoundation\Request
     *
     * @param ReactRequest $reactRequest
     * @param string $content
     * @return SymfonyRequest $syRequest
     */
    protected static function mapRequest(ReactRequest $reactRequest, $content)
    {
        $method  = $reactRequest->getMethod();
        $headers = $reactRequest->getHeaders();
        $query   = $reactRequest->getQuery();
        $post    = [];

        // parse body?
        if (array_key_exists('Content-Type', $headers) &&
            (0 === strpos($headers['Content-Type'], 'application/x-www-form-urlencoded')) &&
            in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true)
        ) {
            parse_str($content, $post);
        }

        $syRequest = new LumenRequest($query, $post, [], [], [], [], $content);

        $syRequest->setMethod($method);
        $syRequest->headers->replace($headers);
        $syRequest->server->set('REQUEST_URI', $reactRequest->getPath());
        $host = null;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'host') {
                $host = $value;
            }
        }
        if ($host === null) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            throw new \RuntimeException('Invalid request.');
        }

        $syRequest->server->set('SERVER_NAME', explode(':', $headers['Host'])[0]);
        if (array_key_exists('X-Forwarded-For', $headers)) {
            $forwardedFor = $headers['X-Forwarded-For'];
            if (is_array($forwardedFor)) {
                $syRequest->server->set('REMOTE_ADDR', $forwardedFor[0]);
            } else {
                $syRequest->server->set('REMOTE_ADDR', $forwardedFor);
            }
        }

        return $syRequest;
    }

    /**
     * Convert Symfony\Component\HttpFoundation\Response to React\Http\Response
     *
     * @param ReactResponse $reactResponse
     * @param SymfonyResponse $syResponse
     */
    protected static function mapResponse(ReactResponse $reactResponse, $syResponse)
    {
        if ($syResponse instanceof SymfonyResponse) {
            $headers = $syResponse->headers->all();
            $reactResponse->writeHead($syResponse->getStatusCode(), $headers);

            // @TODO convert StreamedResponse in an async manner
            if ($syResponse instanceof SymfonyStreamedResponse) {
                ob_start();
                $syResponse->sendContent();
                $content = ob_get_contents();
                ob_end_clean();
            } else {
                $content = $syResponse->getContent();
            }

            $reactResponse->end($content);
        } else {
            $reactResponse->writeHead(200);
            $reactResponse->end($syResponse);
        }

    }

    /**
     * @param $appBootstrap
     * @return string
     * @throws \RuntimeException
     */
    protected function normalizeAppBootstrap($appBootstrap)
    {
        $appBootstrap = str_replace('\\\\', '\\', $appBootstrap);
        if (!class_exists($appBootstrap) && !class_exists('\\'.$appBootstrap)) {
            throw new \RuntimeException('Could not find bootstrap class '.$appBootstrap);
        }

        return $appBootstrap;
    }
}

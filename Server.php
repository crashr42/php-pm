<?php

/**
 * Created by PhpStorm.
 * User: dev
 * Date: 2/2/17
 * Time: 9:31 PM
 */

namespace PHPPM;

use Evenement\EventEmitter;
use React\Http\Request;
use React\Http\Response;
use React\Http\ServerInterface;
use React\Socket\ServerInterface as SocketServerInterface;
use React\Socket\ConnectionInterface;

/** @noinspection ClassOverridesFieldOfSuperClassInspection */
class Server extends EventEmitter implements ServerInterface
{
    protected $io;

    /** @noinspection PhpMissingParentConstructorInspection */
    /**
     * Server constructor.
     * @param SocketServerInterface $io
     */
    public function __construct(SocketServerInterface $io)
    {
        $this->io = $io;

        $this->io->on('connection', function (ConnectionInterface $conn) {
            // TODO: http 1.1 keep-alive
            // TODO: chunked transfer encoding (also for outgoing data)
            // TODO: multipart parsing

            $parser = new RequestHeaderParser();
            $parser->on('headers', function (Request $request, $bodyBuffer) use ($conn, $parser) {
                // attach remote ip to the request as metadata
                $request->remoteAddress = $conn->getRemoteAddress();

                $this->handleRequest($conn, $request, $bodyBuffer);

                $conn->removeListener('data', [$parser, 'feed']);
                $conn->on('end', function () use ($request) {
                    $request->emit('end');
                });
                $conn->on('data', function ($data) use ($request) {
                    $request->emit('data', [$data]);
                });
                $request->on('pause', function () use ($conn) {
                    $conn->emit('pause');
                });
                $request->on('resume', function () use ($conn) {
                    $conn->emit('resume');
                });
            });
            $parser->on('error', function ($exception) use ($conn) {
                $conn->end("HTTP/1.1 500 Internal server error\nContent-Type: text/plain\n\n".$exception->getMessage());
            });

            $conn->on('data', [$parser, 'feed']);
        });
    }

    /**
     * @param ConnectionInterface $conn
     * @param Request $request
     * @param $bodyBuffer
     */
    public function handleRequest(ConnectionInterface $conn, Request $request, $bodyBuffer)
    {
        $response = new Response($conn);
        $response->on('close', [$request, 'close']);

        if (!$this->listeners('request')) {
            $response->end();

            return;
        }

        $this->emit('request', [$request, $response]);
        $request->emit('data', [$bodyBuffer]);
    }
}

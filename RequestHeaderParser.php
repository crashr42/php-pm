<?php

/**
 * Created by PhpStorm.
 * User: dev
 * Date: 2/2/17
 * Time: 10:30 PM
 */

namespace PHPPM;

use Evenement\EventEmitter;
use Exception;
use GuzzleHttp\Psr7 as g7;
use React\Http\Request;

class RequestHeaderParser extends EventEmitter
{
    private $buffer  = '';
    private $maxSize = 131072;

    public function feed($data)
    {
        if (strlen($this->buffer) + strlen($data) > $this->maxSize) {
            $this->emit('error', [new \OverflowException("Maximum header size of {$this->maxSize} exceeded."), $this]);
            $this->removeAllListeners();

            return;
        }

        $this->buffer .= $data;

        if (false !== strpos($this->buffer, "\r\n\r\n")) {
            try {
                $this->parseAndEmitRequest();
            } catch (Exception $exception) {
                $this->emit('error', [$exception]);
            }

            $this->removeAllListeners();
        }
    }

    protected function parseAndEmitRequest()
    {
        list($request, $bodyBuffer) = $this->parseRequest($this->buffer);
        $this->emit('headers', [$request, $bodyBuffer]);
    }

    public function parseRequest($data)
    {
        list($headers, $bodyBuffer) = explode("\r\n\r\n", $data, 2);

        /** @var \GuzzleHttp\Psr7\Request $psrRequest */
        $psrRequest = g7\parse_request($headers);

        $parsedQuery = [];
        $queryString = $psrRequest->getUri()->getQuery();
        if ($queryString) {
            parse_str($queryString, $parsedQuery);
        }

        $headers = array_map(function ($val) {
            if (1 === count($val)) {
                $val = $val[0];
            }

            return $val;
        }, $psrRequest->getHeaders());

        $request = new Request(
            $psrRequest->getMethod(),
            $psrRequest->getUri()->getPath(),
            $parsedQuery,
            $psrRequest->getProtocolVersion(),
            $headers
        );

        return [$request, $bodyBuffer];
    }
}

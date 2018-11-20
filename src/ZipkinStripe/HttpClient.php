<?php

namespace ZipkinStripe;

use Stripe\HttpClient\ClientInterface;
use Throwable;
use Zipkin\Span;
use Zipkin\Tracer;
use const Zipkin\Kind\CLIENT;

class HttpClient implements ClientInterface
{
    private const HTTP_BAD_REQUEST = 400;

    /**
     * @var ClientInterface
     */
    private $realStripeClient;
    /**
     * @var Tracer
     */
    private $tracer;

    public function __construct(ClientInterface $realStripeClient, Tracer $tracer)
    {
        $this->realStripeClient = $realStripeClient;
        $this->tracer = $tracer;
    }

    /**
     * @param string $method The HTTP method being used
     * @param string $absUrl The URL being requested, including domain and protocol
     * @param array $headers Headers to be used in the request (full strings, not KV pairs)
     * @param array $params KV pairs for parameters. Can be nested for arrays and hashes
     * @param bool $hasFile Whether or not $params references a file (via an @ prefix or
     *                         CurlFile)
     *
     * @throws \Stripe\Error\Api
     * @throws \Stripe\Error\ApiConnection
     * @return array An array whose first element is raw request body, second
     *    element is HTTP status code and third array of HTTP headers.
     */
    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $span = $this->startTraceFor($method, $absUrl);
        $response = null;

        try {
            $response = $this->realStripeClient->request($method, $absUrl, $headers, $params, $hasFile);

            $statusCode = (int) $response[1];
            $span->tag('http.status_code', (string) $statusCode);
            if ($statusCode >= self::HTTP_BAD_REQUEST) {
                $span->tag('error', (string) $statusCode);
            }

            return $response;
        } catch (Throwable $e) {
            $span->tag('error', $e->getMessage());
            throw $e;
        } finally {
            if (is_array($response)) {
                $this->tagResponseHeaders($span, $response[2]);
            };

            $span->finish();
        }
    }

    private function startTraceFor($method, $absUrl): Span
    {
        $span = $this->tracer->nextSpan();
        $span->setKind(CLIENT);

        $span->tag('http.method', $method);
        $span->tag('http.url', $absUrl);
        $span->setName('stripe/' . $method);
        $span->start();

        return $span;
    }

    private function tagResponseHeaders(Span $span, array $responseHeaders): void
    {
        $span->tag('stripe.request_id', $responseHeaders['Request-Id']);
        $span->tag('stripe.version', $responseHeaders['Stripe-Version']);
    }
}

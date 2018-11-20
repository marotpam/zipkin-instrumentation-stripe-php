<?php

namespace ZipkinStripe;

use Mockery;
use PHPUnit\Framework\TestCase;
use Stripe\Error\Api;
use Stripe\HttpClient\ClientInterface;
use Zipkin\Reporters\InMemory;
use Zipkin\Samplers\BinarySampler;
use Zipkin\TracingBuilder;

class HttpClientTest extends TestCase
{
    private const METHOD = 'GET';
    private const ABSOLUTE_URL = 'URL';
    private const HEADERS = ['Authorization' => 'Bearer jc'];
    private const PARAMS = ['key' => 'value'];
    private const HAS_FILE = false;
    private const RESPONSE_CODE = 200;
    private const EXCEPTION_MESSAGE = 'exception message';
    private const CLIENT_ERROR_CODE = '400';
    private const STRIPE_REQUEST_ID = 'req_123456';
    private const STRIPE_VERSION = '2018-02-06';

    private $stripeHttpClient;
    private $sut;
    private $realStripeClientResponse;
    private $reporter;
    private $tracing;
    private $actualResponse;
    private $requestError;

    protected function setUp()
    {
        $this->stripeHttpClient = Mockery::spy(ClientInterface::class);
        $this->reporter = new InMemory();
        $this->tracing = TracingBuilder::create()
            ->havingReporter($this->reporter)
            ->havingSampler(BinarySampler::createAsAlwaysSample())
            ->build();
    }

    public function testItSendsTheRequestToTheRealStripeClient()
    {
        $this->whenDoingARequestWithTheClient();

        $this->thenTheRequestShouldHaveBeenPropagatedToTheRealClient();
    }

    public function testItTracesSuccessfulRequests()
    {
        $this->givenASuccessfulResponseFromStripe();

        $this->whenDoingARequestWithTheClient();

        $expectedSpan = [
            'kind' => 'CLIENT',
            'tags' => [
                'http.method' => self::METHOD,
                'http.url' => self::ABSOLUTE_URL,
                'http.status_code' => self::RESPONSE_CODE,
                'stripe.request_id' => self::STRIPE_REQUEST_ID,
                'stripe.version' => self::STRIPE_VERSION,
            ],
            'name' => 'stripe/' . self::METHOD,
        ];

        $this->andItShouldHaveBeenTracedWith($expectedSpan);
        $this->andTheResponseFromTheStripeClientReturned();
    }

    public function testItTracesRequestsWithClientErrors()
    {
        $this->givenARequestWithClientError();

        $this->whenDoingARequestWithTheClient();

        $expectedSpans = [
            'kind' => 'CLIENT',
            'tags' => [
                'http.method' => self::METHOD,
                'http.status_code' => self::CLIENT_ERROR_CODE,
                'http.url' => self::ABSOLUTE_URL,
                'error' => self::CLIENT_ERROR_CODE,
                'stripe.request_id' => self::STRIPE_REQUEST_ID,
                'stripe.version' => self::STRIPE_VERSION,
            ],
            'name' => 'stripe/' . self::METHOD,
        ];

        $this->thenTheRequestShouldHaveBeenPropagatedToTheRealClient();
        $this->andItShouldHaveBeenTracedWith($expectedSpans);
    }

    public function testItTracesFailingRequests()
    {
        $this->givenARequestStripeFailsToProcess();

        $this->whenDoingARequestWithTheClient();

        $expectedSpan = [
            'kind' => 'CLIENT',
            'tags' => [
                'http.method' => self::METHOD,
                'http.url' => self::ABSOLUTE_URL,
                'error' => self::EXCEPTION_MESSAGE,
            ],
            'name' => 'stripe/' . self::METHOD,
        ];

        $this->thenTheRequestErrorShouldBeReturned();
        $this->andItShouldHaveBeenTracedWith($expectedSpan);
    }

    private function givenASuccessfulResponseFromStripe(): void
    {
        $this->realStripeClientResponse = [
            'foo',
            self::RESPONSE_CODE,
            [
                'Request-Id' => self::STRIPE_REQUEST_ID,
                'Stripe-Version' => self::STRIPE_VERSION,
            ],
        ];
        $this->stripeHttpClient
            ->shouldReceive('request')
            ->andReturn($this->realStripeClientResponse);
    }

    private function andItShouldHaveBeenTracedWith(array $expectedSpan): void
    {
        $this->tracing->getTracer()->flush();

        $actualSpan = $this->reporter->flush()[0];
        $this->assertArraySubset($expectedSpan, $actualSpan->toArray());
    }

    private function andTheResponseFromTheStripeClientReturned(): void
    {
        $this->assertSame($this->realStripeClientResponse, $this->actualResponse);
    }

    private function whenDoingARequestWithTheClient()
    {
        try {
            $this->sut = new HttpClient($this->stripeHttpClient, $this->tracing->getTracer());

            $this->actualResponse = $this->sut->request(
                self::METHOD,
                self::ABSOLUTE_URL,
                self::HEADERS,
                self::PARAMS,
                self::HAS_FILE
            );
        } catch (Api $e) {
            $this->requestError = $e;
        }
    }

    private function thenTheRequestShouldHaveBeenPropagatedToTheRealClient(): void
    {
        $this->stripeHttpClient
            ->shouldHaveReceived('request')
            ->with(
                self::METHOD,
                self::ABSOLUTE_URL,
                self::HEADERS,
                self::PARAMS,
                self::HAS_FILE
            );
    }

    private function givenARequestWithClientError(): void
    {
        $realStripeClientResponse = [
            'foo',
            self::CLIENT_ERROR_CODE,
            [
                'Request-Id' => self::STRIPE_REQUEST_ID,
                'Stripe-Version' => self::STRIPE_VERSION,
            ],
        ];
        $this->stripeHttpClient
            ->shouldReceive('request')
            ->andReturn($realStripeClientResponse);
    }

    private function thenTheRequestErrorShouldBeReturned()
    {
        $this->assertEquals(self::EXCEPTION_MESSAGE, $this->requestError->getMessage());
    }

    private function givenARequestStripeFailsToProcess(): void
    {
        $this->stripeHttpClient
            ->shouldReceive('request')
            ->andThrow(new Api(self::EXCEPTION_MESSAGE));
    }
}

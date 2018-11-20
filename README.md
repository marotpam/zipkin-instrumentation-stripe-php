# Zipkin instrumentation for Stripe's PHP library

Stripe PHP client with Zipkin instrumentation

## Installation

```bash
composer require marotpam/zipkin-instrumentation-stripe-php
```

## Usage
You need to override the default HttpClient that [stripe-php](https://github.com/stripe/stripe-php) uses, with
one that includes the Zipkin Tracer shared of your application. For further details on how to use Zipkin
in your PHP applications please refer to [Zipkin's official PHP library](https://github.com/openzipkin/zipkin-php)


```php
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Zipkin\Tracer;
use ZipkinStripe\HttpClient;

/**
 * @param string $stripeSecretKey Stripe API key
 * @param Tracer $tracer Zipkin tracer used across your application
 */
public function initialiseStripeClient(string $stripeSecretKey, Tracer $tracer)
{
    Stripe::setApiKey($stripeSecretKey);

    $instrumentedStripeClient = new HttpClient(CurlClient::instance(), $tracer);

    ApiRequestor::setHttpClient($instrumentedStripeClient);
}

```
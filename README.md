# Apirelio Slim SDK

[Documentation](https://apirelio.com/docs/php/slim) · [Packagist](https://packagist.org/packages/apirelio/slim) · [Apirelio](https://apirelio.com)

Customer-aware API monitoring middleware for Slim 4. It captures route-level operational telemetry without request or response payloads and delegates the shared event contract, privacy filtering and delivery to `apirelio/php-core`.

## Install

```bash
composer require apirelio/slim:^1.0
```

## Configure

```php
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\Slim\ApirelioMiddleware;
use Apirelio\Slim\Config;

$apirelio = new ApirelioMiddleware(
    new Config(
        apiKey: $_ENV['APIRELIO_API_KEY'],
        endpoint: $_ENV['APIRELIO_ENDPOINT'] ?? 'https://apirelio.com',
        service: 'billing-api',
        environment: $_ENV['APP_ENV'] ?? 'production',
        release: $_ENV['APP_RELEASE'] ?? null,
        paths: ['/api/*'],
        bufferPath: __DIR__.'/../var/apirelio/events.ndjson',
    ),
    customerResolver: static fn ($request) => new ApirelioCustomer(
        id: (string) $request->getAttribute('account')->id,
        name: $request->getAttribute('account')->name,
    ),
    applicationResolver: static fn ($request) => new ApirelioApplication(
        id: (string) $request->getAttribute('api_client')->id,
    ),
);

// Slim middleware is LIFO. This order lets Apirelio see the resolved route
// while the error middleware remains the outermost exception boundary.
$app->add($apirelio);
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);
```

## Add safe request context

```php
use Apirelio\Slim\RequestContext;

$app->post('/api/invoices', function ($request, $response) {
    /** @var RequestContext|null $context */
    $context = $request->getAttribute(RequestContext::ATTRIBUTE);
    $context?->addMetadata(['region' => 'eu-central']);
    $context?->setErrorCode('PAYMENT_REQUIRED');

    return $response;
});
```

Only scalar metadata keys listed in `metadataKeys` are retained. Sensitive-looking keys are always rejected. Request bodies, response bodies, query strings, credentials and cookies are never captured.

## Delivery

Set `bufferPath` to use the locked NDJSON buffer from PHP Core. Without it, events use the synchronous HTTP transport. In both modes, transport failures are swallowed and never alter the customer response. Provide `failureHandler` to send SDK delivery failures to your application logger.

## Requirements

- PHP 8.2+
- Slim 4.15+
- a PSR-7 implementation supported by Slim
- ext-curl

## Documentation

See the [Slim setup guide](https://apirelio.com/docs/php/slim) and [Apirelio security model](https://apirelio.com/security).

## License

MIT

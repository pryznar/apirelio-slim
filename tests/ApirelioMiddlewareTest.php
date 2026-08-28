<?php

declare(strict_types=1);

namespace Apirelio\Slim\Tests;

use Apirelio\Core\Contracts\EventTransport;
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\Slim\ApirelioMiddleware;
use Apirelio\Slim\Config;
use Apirelio\Slim\RequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApirelioMiddlewareTest extends TestCase
{
    public function test_it_captures_a_normalized_slim_route_and_customer_context(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(
            new Config(
                apiKey: 'apr_test',
                service: 'billing-api',
                environment: 'test',
                release: '2026.08.12.1',
                metadataKeys: ['region'],
            ),
            customerResolver: static fn (): ApirelioCustomer => new ApirelioCustomer('customer_42', 'Acme', 'growth'),
            applicationResolver: static fn (): ApirelioApplication => new ApirelioApplication('shopify', 'Shopify'),
            transport: $transport,
        );
        $app = AppFactory::create();
        $app->get('/api/customers/{id}', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $context = $request->getAttribute(RequestContext::ATTRIBUTE);
            self::assertInstanceOf(RequestContext::class, $context);
            $context->addMetadata(['region' => 'eu-central', 'password' => 'never-store']);
            $context->setErrorCode('PAYMENT_REQUIRED');

            return $response->withStatus(402)->withHeader('Content-Length', '12');
        })->setName('customer.show');
        $app->add($middleware);
        $app->addRoutingMiddleware();

        $request = (new ServerRequestFactory)
            ->createServerRequest('GET', '/api/customers/123')
            ->withHeader('X-Api-Version', '2026-08');
        $response = $app->handle($request);

        self::assertSame(402, $response->getStatusCode());
        self::assertCount(1, $transport->events);
        self::assertSame('/api/customers/{id}', $transport->events[0]['route']);
        self::assertSame('customer.show', $transport->events[0]['route_name']);
        self::assertSame('customer_42', $transport->events[0]['customer_id']);
        self::assertSame('shopify', $transport->events[0]['application_id']);
        self::assertSame('PAYMENT_REQUIRED', $transport->events[0]['error_code']);
        self::assertSame(['region' => 'eu-central'], $transport->events[0]['metadata']);
        self::assertSame('slim', $transport->events[0]['sdk']);
        self::assertSame('1.0.0', $transport->events[0]['sdk_version']);
        self::assertSame(12, $transport->events[0]['response_bytes']);
    }

    public function test_it_records_and_rethrows_an_unhandled_exception(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(new Config(apiKey: 'apr_test'), transport: $transport);
        $app = AppFactory::create();
        $app->get('/api/fail', static fn (): never => throw new RuntimeException('private detail'));
        $app->add($middleware);
        $app->addRoutingMiddleware();

        try {
            $app->handle((new ServerRequestFactory)->createServerRequest('GET', '/api/fail'));
            self::fail('The application exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('private detail', $exception->getMessage());
        }

        self::assertCount(1, $transport->events);
        self::assertSame(500, $transport->events[0]['status']);
        self::assertSame(['exception' => RuntimeException::class], $transport->events[0]['metadata']);
        self::assertArrayNotHasKey('message', $transport->events[0]['metadata']);
    }

    public function test_telemetry_failure_never_changes_the_customer_response(): void
    {
        $failures = [];
        $transport = new class implements EventTransport
        {
            public function send(array $events): void
            {
                throw new RuntimeException('ingestion unavailable');
            }
        };
        $middleware = new ApirelioMiddleware(
            new Config(apiKey: 'apr_test'),
            transport: $transport,
            failureHandler: static function (\Throwable $failure) use (&$failures): void {
                $failures[] = $failure->getMessage();
            },
        );
        $app = AppFactory::create();
        $app->get('/api/health', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response->withStatus(204));
        $app->add($middleware);
        $app->addRoutingMiddleware();

        $response = $app->handle((new ServerRequestFactory)->createServerRequest('GET', '/api/health'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['ingestion unavailable'], $failures);
    }

    public function test_it_skips_unmatched_paths_and_empty_keys(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(new Config(apiKey: 'apr_test'), transport: $transport);
        $app = AppFactory::create();
        $app->get('/health', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response);
        $app->add($middleware);
        $app->addRoutingMiddleware();
        $app->handle((new ServerRequestFactory)->createServerRequest('GET', '/health'));

        self::assertSame([], $transport->events);
    }
}

final class RecordingTransport implements EventTransport
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function send(array $events): void
    {
        $this->events = array_merge($this->events, $events);
    }
}

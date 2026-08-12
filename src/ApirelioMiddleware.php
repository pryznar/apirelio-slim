<?php

declare(strict_types=1);

namespace Apirelio\Slim;

use Apirelio\Core\Config\BufferConfig;
use Apirelio\Core\Config\TransportConfig;
use Apirelio\Core\Contracts\EventTransport;
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\Core\Data\EventContext;
use Apirelio\Core\EventFactory;
use Apirelio\Core\MetadataSanitizer;
use Apirelio\Core\Transport\FileBufferTransport;
use Apirelio\Core\Transport\HttpBatchTransport;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Throwable;

final class ApirelioMiddleware implements MiddlewareInterface
{
    public const VERSION = '0.1.0';

    /** @var null|Closure(ServerRequestInterface): ?ApirelioCustomer */
    private ?Closure $customerResolver;

    /** @var null|Closure(ServerRequestInterface): (ApirelioApplication|string|null) */
    private ?Closure $applicationResolver;

    /** @var null|Closure(Throwable): void */
    private ?Closure $failureHandler;

    /**
     * @param null|callable(ServerRequestInterface): ?ApirelioCustomer $customerResolver
     * @param null|callable(ServerRequestInterface): (ApirelioApplication|string|null) $applicationResolver
     * @param null|callable(Throwable): void $failureHandler
     */
    public function __construct(
        private readonly Config $config,
        ?callable $customerResolver = null,
        ?callable $applicationResolver = null,
        ?EventTransport $transport = null,
        ?callable $failureHandler = null,
        private readonly EventFactory $events = new EventFactory,
        private readonly MetadataSanitizer $metadata = new MetadataSanitizer,
    ) {
        $this->customerResolver = $customerResolver === null ? null : Closure::fromCallable($customerResolver);
        $this->applicationResolver = $applicationResolver === null ? null : Closure::fromCallable($applicationResolver);
        $this->failureHandler = $failureHandler === null ? null : Closure::fromCallable($failureHandler);
        $this->transport = $transport ?? $this->defaultTransport();
    }

    private readonly EventTransport $transport;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->shouldCapture($request)) {
            return $handler->handle($request);
        }

        $startedAt = hrtime(true);
        $context = new RequestContext;
        $request = $request->withAttribute(RequestContext::ATTRIBUTE, $context);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $exception) {
            $this->capture($request, null, $context, $startedAt, $exception);

            throw $exception;
        }

        $this->capture($request, $response, $context, $startedAt);

        return $response;
    }

    private function capture(
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        RequestContext $requestContext,
        int $startedAt,
        ?Throwable $exception = null,
    ): void {
        try {
            $metadata = $this->requestMetadata($request, $requestContext);
            if ($exception !== null) {
                $metadata['exception'] = $exception::class;
            }

            $this->transport->send([$this->events->create(new EventContext(
                service: $this->config->service,
                environment: $this->config->environment,
                method: $request->getMethod(),
                route: $this->route($request),
                routeName: $this->routeName($request),
                status: $response?->getStatusCode() ?? 500,
                durationMilliseconds: (int) round((hrtime(true) - $startedAt) / 1_000_000),
                requestBytes: $this->contentLength($request->getHeaderLine('Content-Length')),
                responseBytes: $this->contentLength($response?->getHeaderLine('Content-Length') ?? ''),
                customer: $this->resolveCustomer($request),
                application: $this->resolveApplication($request),
                apiVersion: $this->stringOrNull($request->getHeaderLine('X-Api-Version')),
                sdk: 'slim',
                sdkVersion: self::VERSION,
                release: $this->config->release,
                errorCode: $requestContext->errorCode(),
                metadata: $this->metadata->sanitize($metadata, $this->config->metadataKeys),
            ))]);
        } catch (Throwable $failure) {
            if ($this->failureHandler === null) {
                return;
            }

            try {
                ($this->failureHandler)($failure);
            } catch (Throwable) {
                // Telemetry must never change the customer response.
            }
        }
    }

    private function shouldCapture(ServerRequestInterface $request): bool
    {
        if (! $this->config->enabled || $this->config->apiKey === '') {
            return false;
        }

        $path = '/'.ltrim($request->getUri()->getPath(), '/');
        foreach ($this->config->paths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCustomer(ServerRequestInterface $request): ?ApirelioCustomer
    {
        return $this->customerResolver === null ? null : ($this->customerResolver)($request);
    }

    private function resolveApplication(ServerRequestInterface $request): ?ApirelioApplication
    {
        if ($this->applicationResolver === null) {
            return null;
        }

        $application = ($this->applicationResolver)($request);

        return is_string($application) ? new ApirelioApplication($application) : $application;
    }

    /** @return array<string, bool|float|int|string|null> */
    private function requestMetadata(ServerRequestInterface $request, RequestContext $context): array
    {
        $metadata = $context->metadata();
        foreach ($this->config->captureHeaders as $header) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                $metadata['header.'.strtolower($header)] = mb_substr($value, 0, 500);
            }
        }

        return $metadata;
    }

    private function route(ServerRequestInterface $request): string
    {
        try {
            $pattern = RouteContext::fromRequest($request)->getRoute()?->getPattern();
            if (is_string($pattern) && $pattern !== '') {
                return '/'.ltrim($pattern, '/');
            }
        } catch (Throwable) {
            // Fall back to a cardinality-safe path when routing middleware is absent.
        }

        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        $segments = array_map(static function (string $segment): string {
            if (
                ctype_digit($segment)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment) === 1
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment) === 1
            ) {
                return '{id}';
            }

            return $segment;
        }, $segments);

        return '/'.implode('/', $segments);
    }

    private function routeName(ServerRequestInterface $request): ?string
    {
        try {
            return $this->stringOrNull(RouteContext::fromRequest($request)->getRoute()?->getName());
        } catch (Throwable) {
            return null;
        }
    }

    private function defaultTransport(): EventTransport
    {
        $transport = new HttpBatchTransport(
            new CurlIngestionClient,
            new TransportConfig(
                endpoint: $this->config->endpoint,
                apiKey: $this->config->apiKey,
                timeoutSeconds: $this->config->timeoutSeconds,
                connectTimeoutSeconds: $this->config->connectTimeoutSeconds,
            ),
        );

        return $this->config->bufferPath === null ? $transport : new FileBufferTransport(
            $transport,
            new BufferConfig(
                path: $this->config->bufferPath,
                batchSize: $this->config->batchSize,
                flushIntervalSeconds: $this->config->flushIntervalSeconds,
            ),
        );
    }

    private function contentLength(string $value): int
    {
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

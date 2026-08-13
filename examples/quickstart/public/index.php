<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\Slim\ApirelioMiddleware;
use Apirelio\Slim\Config;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->get('/api/invoices/{id}', static fn ($request, $response) => $response);
$app->add(new ApirelioMiddleware(
    new Config(apiKey: getenv('APIRELIO_API_KEY') ?: '', service: 'github-quickstart', environment: 'development'),
    customerResolver: static fn () => new ApirelioCustomer('customer_42', 'Acme Europe', 'growth'),
));
$app->addRoutingMiddleware();
$app->run();


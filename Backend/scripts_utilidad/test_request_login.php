<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use Illuminate\Http\Request;

// Bind a listener to log exception details to stdout
\Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event) {
    if ($event->response->isServerError()) {
        echo "Server error handled.\n";
    }
});

// Setup custom log handler to print errors to stdout
\Illuminate\Support\Facades\Log::extend('stdout', function ($app, $config) {
    return new \Monolog\Logger('stdout', [
        new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG)
    ]);
});
config(['logging.default' => 'stdout']);

$request = Request::create('/api/v1/login', 'POST', [
    'email' => 'liz@decorarte360.com',
    'password' => 'password123'
]);

// Inyectar X-Device-Fingerprint si es necesario
$request->headers->set('Accept', 'application/json');
$request->headers->set('Content-Type', 'application/json');

$response = $kernel->handle($request);

echo "Status Code: " . $response->getStatusCode() . "\n";
echo "Content: \n" . $response->getContent() . "\n";

<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        App\Providers\HorizonServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\AppServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(TrustProxies::class);

        $middleware->web();

        $middleware->validateCsrfTokens(
            except: [
                'admin/login',
                'portal/login',
                'webhooks/acuity',
                'webhooks/twilio/enrollment-status',
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
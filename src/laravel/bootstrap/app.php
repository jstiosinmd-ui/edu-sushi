<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        // Only enabled by the host when traffic comes through its HTTPS proxy.
        if (env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: trim(env('TRUSTED_PROXIES')), headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        }
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['active' => ActiveAccount::class, 'admin' => AdminOnly::class]);
    })->withExceptions(function (Exceptions $exceptions): void {})->create();

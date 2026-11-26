<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // POST /api/broadcasting/auth у групі `api`: stateless, без сесії й CSRF.
    // Хто має право на канал, вирішує guard каналу (див. routes/channels.php).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api']])
    ->withCommands([
        __DIR__.'/../app/Shared/Console',
        __DIR__.'/../app/Modules/Listings/Console',
        __DIR__.'/../app/Modules/AI/Console',
        __DIR__.'/../app/Modules/Search/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

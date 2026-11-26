<?php

declare(strict_types=1);

use App\Shared\Http\Problems\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
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
        $exceptions->shouldRenderJsonWhen(ProblemRenderer::wantsProblem(...));

        // Усі помилки API — Problem Details (RFC 9457) з машиночитним `code`, див. ProblemRenderer.
        $exceptions->render(static fn (Throwable $e, Request $request): ?JsonResponse => new ProblemRenderer(
            debug: config()->boolean('app.debug'),
        )->render($e, $request));
    })->create();

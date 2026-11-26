<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Demo\DemoMode;
use App\Shared\Http\Problems\ApiProblem;
use App\Shared\Http\Problems\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * На публічному демо запис квартир закрито: інакше будь-хто через «Try it out» у Swagger UI
 * змінює чи видаляє дані, які бачать інші гості, і ставить у чергу платну переіндексацію.
 *
 * Middleware, а не умовна реєстрація маршрутів: специфікація OpenAPI не залежить від DEMO_MODE
 * (CI порівнює її з закоміченою), а клієнт отримує зрозумілий 403 з `code`, а не загадковий 404/405.
 */
final class ReadOnlyInDemo
{
    public const string ALIAS = 'demo.read-only';

    public const string DETAIL = 'Демо працює лише на читання: створення, зміна й видалення квартир вимкнено.';

    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::enabled()) {
            throw new ApiProblem(ErrorCode::DemoReadOnly, self::DETAIL);
        }

        $response = $next($request);
        assert($response instanceof Response);

        return $response;
    }
}

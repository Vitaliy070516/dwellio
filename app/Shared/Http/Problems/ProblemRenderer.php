<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Єдиний формат помилок API: Problem Details (RFC 9457), `application/problem+json`.
 *
 *     {"type": "urn:dwellio:problem:ai_budget_exhausted", "title": "Service Unavailable",
 *      "status": 503, "detail": "Daily AI budget is exhausted. Try again tomorrow.",
 *      "code": "ai_budget_exhausted"}
 *
 * Стандарт, а не власний конверт: його знають API-шлюзи й генератори клієнтів, а розширення
 * дозволені, тож `code` (машиночитний ключ) і `errors` (поля валідації в звичній для Laravel формі)
 * додаються без порушення RFC. Заголовки винятку (`Retry-After`, `X-RateLimit-*`) зберігаються.
 *
 * Викликається з bootstrap/app.php лише для API; веб-сторінки демо лишаються HTML.
 */
final class ProblemRenderer
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public function __construct(private readonly bool $debug) {}

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::wantsProblem($request) || $e instanceof HttpResponseException) {
            // HttpResponseException уже несе готову відповідь, яку хтось сформував свідомо.
            return null;
        }

        return match (true) {
            $e instanceof ApiProblem => $this->problem($e->errorCode, $e->getStatusCode(), $e->getMessage(), $e->getHeaders()),
            $e instanceof ValidationException => $this->problem(
                ErrorCode::ValidationFailed,
                $e->status,
                'The given data was invalid.',
                extra: ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => $this->problem(ErrorCode::Unauthenticated, 401, 'Unauthenticated.'),
            $e instanceof HttpExceptionInterface => $this->problem(
                ErrorCode::forStatus($e->getStatusCode()),
                $e->getStatusCode(),
                $this->httpDetail($e),
                $e->getHeaders(),
            ),
            // Текст непередбаченого винятку може містити SQL, шляхи чи ключі: назовні лише в debug.
            default => $this->problem(ErrorCode::ServerError, 500, $this->debug ? $e->getMessage() : 'Server error.'),
        };
    }

    public static function wantsProblem(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @param  array<string, mixed>  $extra
     */
    private function problem(ErrorCode $code, int $status, string $detail, array $headers = [], array $extra = []): JsonResponse
    {
        $body = [
            'type' => $code->type(),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail !== '' ? $detail : (Response::$statusTexts[$status] ?? 'Error'),
            'code' => $code->value,
            ...$extra,
        ];

        return new JsonResponse($body, $status, [...$headers, 'Content-Type' => self::CONTENT_TYPE]);
    }

    private function httpDetail(HttpExceptionInterface $e): string
    {
        // ModelNotFoundException → NotFoundHttpException з текстом "No query results for model [App\…]":
        // назва класу — внутрішня деталь, клієнту досить «не знайдено».
        if ($e->getPrevious() instanceof ModelNotFoundException) {
            return 'Resource not found.';
        }

        return $e->getMessage();
    }
}

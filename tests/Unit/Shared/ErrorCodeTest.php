<?php

declare(strict_types=1);

use App\Shared\Http\Problems\ApiProblem;
use App\Shared\Http\Problems\ErrorCode;

it('maps every framework status back to the same code', function (ErrorCode $code): void {
    expect(ErrorCode::forStatus($code->status()))->toBe($code);
})->with([
    ErrorCode::ValidationFailed,
    ErrorCode::NotFound,
    ErrorCode::MethodNotAllowed,
    ErrorCode::Unauthenticated,
    ErrorCode::Forbidden,
    ErrorCode::RateLimited,
    ErrorCode::ServerError,
]);

it('falls back to http_error for other 4xx and server_error for other 5xx', function (): void {
    expect(ErrorCode::forStatus(400))->toBe(ErrorCode::HttpError)
        ->and(ErrorCode::forStatus(419))->toBe(ErrorCode::HttpError)
        ->and(ErrorCode::forStatus(502))->toBe(ErrorCode::ServerError);
});

it('gives every code an error status and a URN type', function (ErrorCode $code): void {
    expect($code->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(600)
        ->and($code->type())->toBe('urn:dwellio:problem:'.$code->value)
        ->and($code->value)->toMatch('/^[a-z]+(_[a-z]+)*$/');
})->with(ErrorCode::cases());

it('derives the HTTP status of an ApiProblem from its code', function (): void {
    $problem = new ApiProblem(ErrorCode::AiBudgetExhausted, 'Daily AI budget is exhausted.', ['Retry-After' => '60']);

    expect($problem->getStatusCode())->toBe(503)
        ->and($problem->getMessage())->toBe('Daily AI budget is exhausted.')
        ->and($problem->getHeaders())->toBe(['Retry-After' => '60']);
});

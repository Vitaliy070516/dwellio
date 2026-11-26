<?php

declare(strict_types=1);

use App\Modules\Listings\Models\Listing;
use Illuminate\Support\Facades\Route;

/*
 * Єдиний формат помилок API (RFC 9457). Контракт для клієнтів: тип вмісту, обов'язкові поля,
 * машиночитний `code`, збережені заголовки, і що внутрішні деталі не витікають назовні.
 */

const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code'];

it('answers 404 for a missing listing as problem+json without leaking the model class', function (): void {
    $response = $this->getJson('/api/listings/999999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'urn:dwellio:problem:not_found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Resource not found.',
            'code' => 'not_found',
        ]);

    expect($response->getContent())->not->toContain('App\\')->not->toContain('No query results');
});

it('answers 404 for a draft the same way as for a missing listing', function (): void {
    $draft = Listing::factory()->create(['status' => 'draft']);

    $this->getJson("/api/listings/{$draft->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('keeps field errors in the Laravel shape so assertJsonValidationErrors and clients still work', function (): void {
    $this->getJson('/api/search?price_max=abc&rooms[]=99')
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure([...PROBLEM_KEYS, 'errors' => ['price_max', 'rooms.0']])
        ->assertJsonValidationErrors(['price_max', 'rooms.0'])
        ->assertJsonMissingPath('message');
});

it('answers 405 with method_not_allowed', function (): void {
    $this->patchJson('/api/listings/1', [])
        ->assertMethodNotAllowed()
        ->assertJsonPath('code', 'method_not_allowed');
});

it('answers an unknown API route with not_found even without Accept: application/json', function (): void {
    // Клієнт без заголовка Accept (curl, скрипт) теж отримує JSON, а не HTML-сторінку 404.
    $this->get('/api/no-such-endpoint')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'not_found');
});

it('keeps Retry-After and rate-limit headers on 429', function (): void {
    config(['dwellio.search.rate_limit.per_minute_per_ip' => 1]);

    $this->getJson('/api/search?rooms[]=2')->assertOk();
    $response = $this->getJson('/api/search?rooms[]=2')
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('X-RateLimit-Limit', '1');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('hides the text of an unexpected exception unless debug is on', function (bool $debug, string $detail): void {
    config(['app.debug' => $debug]);
    Route::get('api/__test/boom', static fn () => throw new RuntimeException('SQLSTATE[08006] password=secret'));

    $this->getJson('/api/__test/boom')
        ->assertInternalServerError()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'urn:dwellio:problem:server_error',
            'title' => 'Internal Server Error',
            'status' => 500,
            'detail' => $detail,
            'code' => 'server_error',
        ]);
})->with([
    'prod' => [false, 'Server error.'],
    'debug' => [true, 'SQLSTATE[08006] password=secret'],
]);

it('leaves demo web pages as HTML', function (): void {
    $this->withoutVite();

    $this->get('/listings/999999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

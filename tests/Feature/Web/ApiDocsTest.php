<?php

declare(strict_types=1);

use App\Shared\Http\Problems\ErrorCode;
use App\Web\Controllers\ApiDocsController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * Swagger UI і закомічена специфікація. Що вона збігається з кодом, перевіряє окремий крок CI
 * (`scramble:export` + `git diff`); тут — інваріанти самого файла, які легко зламати ручною правкою.
 */

/** @return array<string, mixed> */
function committedSpec(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(base_path(ApiDocsController::SPEC_PATH)), true, flags: JSON_THROW_ON_ERROR);
}

it('serves Swagger UI pointing at the committed spec', function (): void {
    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('id="swagger-ui"', false)
        ->assertSee('data-spec-url="/docs/api.json"', false);
});

it('serves the committed spec as JSON', function (): void {
    $response = $this->get('/docs/api.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    // BinaryFileResponse (sendfile) не має тіла в пам'яті — читаємо файл, який вона віддає.
    $file = $response->baseResponse;
    expect($file)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($file->getFile()->getRealPath())->toBe(realpath(base_path(ApiDocsController::SPEC_PATH)))
        ->and(committedSpec()['openapi'])->toStartWith('3.1.')
        ->and(committedSpec()['info']['title'])->toBe('Dwellio API');
});

it('documents every public API endpoint under a relative /api server', function (): void {
    $spec = committedSpec();

    // Відносний сервер: специфікація однакова в CI, локально і за тунелем (не залежить від APP_URL).
    expect($spec['servers'])->toBe([['url' => '/api']])
        ->and(array_keys($spec['paths']))->toEqualCanonicalizing([
            '/listings',
            '/listings/{listing}',
            '/listings/{listing}/ads',
            '/listing-ads/{ad}',
            '/search',
        ]);
});

it('describes every error response as problem+json', function (): void {
    $errors = [];
    foreach (committedSpec()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status >= 400) {
                    $errors["{$method} {$path} {$status}"] = array_keys($response['content'] ?? []);
                }
            }
        }
    }

    expect($errors)->not->toBeEmpty()
        ->and(array_unique(array_merge(...array_values($errors))))->toBe(['application/problem+json']);
});

it('lists every ErrorCode in the Problem schema', function (): void {
    $codes = committedSpec()['components']['schemas']['Problem']['properties']['code']['enum'];

    expect($codes)->toBe(array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases()));
});

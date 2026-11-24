<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Services\FakeEmbeddingClient;

it('returns a vector of the configured dimensions with unit length', function (): void {
    $embedding = (new FakeEmbeddingClient(1024))->embed('тиха квартира біля метро');

    expect($embedding->dimensions())->toBe(1024)
        ->and($embedding->model)->toBe('fake-embedding')
        ->and(array_sum(array_map(static fn (float $v): float => $v * $v, $embedding->vector)))
        ->toEqualWithDelta(1.0, 1e-9);
});

it('is deterministic: same text, same vector; different text, different vector', function (): void {
    $client = new FakeEmbeddingClient(64);

    expect($client->embed('кіт')->vector)->toBe($client->embed('кіт')->vector)
        ->and($client->embed('кіт')->vector)->not->toBe($client->embed('собака')->vector);
});

it('supports any dimensionality, including more than one hash block', function (int $dimensions): void {
    expect((new FakeEmbeddingClient($dimensions))->embed('текст')->dimensions())->toBe($dimensions);
})->with([1, 31, 32, 33, 1024]);

it('records embedded texts', function (): void {
    $client = new FakeEmbeddingClient(8);
    $client->embed('перший');
    $client->embed('другий');

    expect($client->embeddedTexts())->toBe(['перший', 'другий']);
});

it('rejects empty and whitespace-only text without recording it', function (): void {
    $client = new FakeEmbeddingClient(8);

    expect(fn () => $client->embed("  \n"))->toThrow(InvalidEmbeddingException::class)
        ->and($client->embeddedTexts())->toBe([]);
});

it('fails exactly once when told to, then recovers', function (): void {
    $client = (new FakeEmbeddingClient(8))->failNextWith(new EmbeddingFailedException('provider down'));

    expect(fn () => $client->embed('текст'))->toThrow(EmbeddingFailedException::class, 'provider down')
        ->and($client->embed('текст')->dimensions())->toBe(8);
});

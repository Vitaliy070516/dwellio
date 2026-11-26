<?php

declare(strict_types=1);

use App\Modules\Search\Support\QueryNormalizer;

it('maps cosmetic variants of a query to one cache key', function (string $variant): void {
    expect(QueryNormalizer::forCacheKey($variant))->toBe('тихо біля метро можна з котом');
})->with([
    'canonical' => 'тихо біля метро можна з котом',
    'case and punctuation' => 'Тихо, біля метро! Можна з котом.',
    'extra whitespace' => "  тихо   біля\tметро\nможна з котом ",
    'quotes and brackets' => '«тихо» (біля метро), можна з котом?!',
    'em dash as separator' => 'тихо — біля метро — можна з котом',
    'trailing ellipsis' => 'тихо біля метро можна з котом…',
]);

it('unifies the many spellings of the Ukrainian apostrophe', function (string $apostrophe): void {
    expect(QueryNormalizer::forCacheKey("п{$apostrophe}ять хвилин"))->toBe("п'ять хвилин");
})->with(["'", '’', 'ʼ', '‘', '`']);

it('keeps hyphens, slashes and apostrophes inside words and numbers', function (): void {
    expect(QueryNormalizer::forCacheKey('1-2 кімнати, 2/3 поверх, Солом’янський'))
        ->toBe("1-2 кімнати 2/3 поверх солом'янський")
        ->and(QueryNormalizer::forCacheKey('1–2 кімнати'))->toBe('1-2 кімнати');
});

it('does not merge queries that may differ in meaning', function (string $a, string $b): void {
    expect(QueryNormalizer::hash($a))->not->toBe(QueryNormalizer::hash($b));
})->with([
    'word order' => ['тихо біля метро', 'біля метро тихо'],
    'range vs two numbers' => ['1-2 кімнати', '1 2 кімнати'],
    'number grouping' => ['до 20 000', 'до 20000'],
]);

it('applies NFKC so look-alike characters share a key', function (): void {
    // Повноширинні цифри (U+FF12 U+FF10) — типовий результат копіювання з азійських розкладок.
    expect(QueryNormalizer::forCacheKey('до ２０ тисяч'))->toBe('до 20 тисяч');
});

it('returns an empty key for punctuation-only input without crashing', function (): void {
    expect(QueryNormalizer::forCacheKey('?!… —'))->toBe('');
});

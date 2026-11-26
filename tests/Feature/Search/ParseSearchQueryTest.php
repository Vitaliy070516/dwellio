<?php

declare(strict_types=1);

use App\Modules\AI\Exceptions\StructuredOutputFailedException;
use App\Modules\Listings\Enums\District;
use App\Modules\Search\Actions\ParseSearchQuery;
use App\Modules\Search\DTO\ParsedQuery;

/**
 * Вихід LLM — недовірений ввід. Кожен збій має давати fallback «увесь запит як текст»,
 * а не 500 для користувача.
 */
function parse(string $query): ParsedQuery
{
    return app(ParseSearchQuery::class)->handle($query);
}

it('maps a valid LLM response to filters and a semantic remainder', function (): void {
    fakeLlm()->respondWith([
        'price_min' => null,
        'price_max' => 20000,
        'rooms' => [2],
        'districts' => ['podilskyi'],
        'pets_allowed' => true,
        'furnished' => null,
        'max_metro_distance_m' => 500,
        'semantic_query' => 'тиха квартира',
    ]);

    $parsed = parse('тихо, до 20 тисяч, 2 кімнати, Поділ, з котом, біля метро');

    expect($parsed->parsedByLlm)->toBeTrue()
        ->and($parsed->filters->priceMax)->toBe(20000)
        ->and($parsed->filters->rooms)->toBe([2])
        ->and($parsed->filters->districts)->toBe([District::Podilskyi])
        ->and($parsed->filters->petsAllowed)->toBeTrue()
        ->and($parsed->filters->furnished)->toBeNull()
        ->and($parsed->filters->maxMetroDistanceM)->toBe(500)
        ->and($parsed->semanticText)->toBe('тиха квартира');
});

it('falls back to plain text when the provider is down', function (): void {
    fakeLlm()->failNextWith(new StructuredOutputFailedException('Connection refused'));

    $parsed = parse('тиха квартира');

    expect($parsed->parsedByLlm)->toBeFalse()
        ->and($parsed->filters->isEmpty())->toBeTrue()
        ->and($parsed->semanticText)->toBe('тиха квартира');
});

it('falls back when the LLM returns values of the wrong type', function (array $payload): void {
    fakeLlm()->respondWith($payload);

    $parsed = parse('щось тихе');

    expect($parsed->parsedByLlm)->toBeFalse()
        ->and($parsed->semanticText)->toBe('щось тихе');
})->with([
    'price is not a number' => [['price_max' => 'дешево']],
    'unknown district' => [['districts' => ['atlantis']]],
    'rooms out of range' => [['rooms' => [99]]],
    'rooms is a string' => [['rooms' => 'two']],
    'negative price' => [['price_min' => -5]],
]);

it('searches by the original query when the LLM extracted nothing', function (): void {
    fakeLlm()->respondWith([]);

    $parsed = parse('якась квартира');

    expect($parsed->parsedByLlm)->toBeTrue()
        ->and($parsed->filters->isEmpty())->toBeTrue()
        ->and($parsed->semanticText)->toBe('якась квартира');
});

it('swaps an inverted price range instead of returning nothing', function (): void {
    fakeLlm()->respondWith(['price_min' => 30000, 'price_max' => 20000]);

    $parsed = parse('від 30 до 20 тисяч');

    expect($parsed->filters->priceMin)->toBe(20000)
        ->and($parsed->filters->priceMax)->toBe(30000);
});

it('deduplicates rooms and districts', function (): void {
    fakeLlm()->respondWith(['rooms' => [2, 2, 1], 'districts' => ['podilskyi', 'podilskyi']]);

    $parsed = parse('1-2 кімнати на Подолі');

    expect($parsed->filters->rooms)->toBe([2, 1])
        ->and($parsed->filters->districts)->toHaveCount(1);
});

it('does not call the LLM twice for the same query', function (): void {
    fakeLlm()->respondWith(['price_max' => 15000, 'semantic_query' => '']);

    parse('до 15 тисяч');
    $second = parse('  До 15 ТИСЯЧ ');

    expect(fakeLlm()->prompts())->toHaveCount(1)
        ->and($second->filters->priceMax)->toBe(15000);
});

it('does not cache failures', function (): void {
    fakeLlm()->failNextWith(new StructuredOutputFailedException('timeout'));
    parse('тиха квартира');

    fakeLlm()->respondWith(['semantic_query' => 'тиха']);
    $second = parse('тиха квартира');

    expect($second->parsedByLlm)->toBeTrue();
});

it('skips the LLM entirely for a blank query', function (): void {
    $parsed = parse('   ');

    expect($parsed->semanticText)->toBe('')
        ->and(fakeLlm()->prompts())->toBe([]);
});

it('drops filters the LLM invented and caches the cleaned result', function (): void {
    // Реальна галюцинація qwen2.5:3b: район і кімнати, яких у запиті немає.
    fakeLlm()->respondWith(['rooms' => [2], 'districts' => ['shevchenkivskyi'], 'semantic_query' => 'зелений двір']);

    $first = parse('вікна у зелений двір');
    $cached = parse('вікна у зелений двір');

    expect($first->filters->isEmpty())->toBeTrue()
        ->and($first->semanticText)->toBe('зелений двір')
        ->and($cached->filters->isEmpty())->toBeTrue()
        ->and(fakeLlm()->prompts())->toHaveCount(1);
});

it('fixes a price bound pointing the wrong way', function (): void {
    fakeLlm()->respondWith(['price_min' => 15000, 'price_max' => 15000, 'districts' => ['holosiivskyi']]);

    $parsed = parse('Голосіївський до 15 тисяч');

    expect($parsed->filters->priceMin)->toBeNull()
        ->and($parsed->filters->priceMax)->toBe(15000)
        ->and($parsed->filters->districts)->toBe([District::Holosiivskyi]);
});

<?php

declare(strict_types=1);

use App\Modules\Search\Support\FilterGrounding;

/**
 * Кейси взято з реального прогону `search:eval` на qwen2.5:3b: саме такі галюцинації
 * викидали релевантні квартири з видачі.
 *
 * @param  array<string, mixed>  $llm
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function ground(array $llm, string $query): array
{
    return FilterGrounding::apply($llm, $query);
}

it('drops a district the user never mentioned', function (): void {
    [$filters, $dropped] = ground(['districts' => ['shevchenkivskyi'], 'pets_allowed' => true], 'тиха квартра з катом');

    expect($filters['districts'])->toBe([])
        ->and($filters['pets_allowed'])->toBeTrue()
        ->and($dropped)->toBe(['districts']);
});

it('keeps districts named in any grammatical case or language', function (string $query, string $slug): void {
    [$filters, $dropped] = ground(['districts' => [$slug]], $query);

    expect($filters['districts'])->toBe([$slug])->and($dropped)->toBe([]);
})->with([
    ['двокімнатна на Подолі', 'podilskyi'],
    ['квартира на Подоле', 'podilskyi'],
    ['однушка на Оболоні', 'obolonskyi'],
    ['Солом’янський район', 'solomianskyi'],
    ['Голосіївський до 15 тисяч', 'holosiivskyi'],
    ['біля Академмістечка', 'sviatoshynskyi'],
]);

it('accepts area names that cover several districts', function (): void {
    [$filters] = ground(['districts' => ['darnytskyi', 'dniprovskyi', 'obolonskyi']], 'з котом на лівому березі');

    // Оболонь — правий берег: її модель вигадала, лівобережні лишаються.
    expect($filters['districts'])->toBe(['darnytskyi', 'dniprovskyi']);
});

it('drops rooms, metro, pets and furniture without support in the text', function (): void {
    [$filters, $dropped] = ground(
        ['rooms' => [2], 'max_metro_distance_m' => 500, 'pets_allowed' => true, 'furnished' => true],
        'вікна у зелений двір',
    );

    expect($filters)->toMatchArray(['rooms' => [], 'max_metro_distance_m' => null, 'pets_allowed' => null, 'furnished' => null])
        ->and($dropped)->toBe(['rooms', 'max_metro_distance_m', 'pets_allowed', 'furnished']);
});

it('keeps grounded rooms, metro, pets and furniture', function (): void {
    $llm = ['rooms' => [1], 'max_metro_distance_m' => 400, 'pets_allowed' => true, 'furnished' => false];

    [$filters, $dropped] = ground($llm, 'однокімнатна, 5 хв пішки до метро, з собакою, без меблів');

    expect($filters)->toMatchArray($llm)->and($dropped)->toBe([]);
});

it('drops a price that matches no number in the query', function (string $query, array $llm): void {
    [$filters, $dropped] = ground($llm, $query);

    expect($filters['price_min'])->toBeNull()
        ->and($filters['price_max'])->toBeNull()
        ->and($dropped)->not->toBe([]);
})->with([
    'minutes are not a price' => ['5 хвилин пішки до метро', ['price_max' => 500]],
    '"cheap" is not a number' => ['однокімнатна недорого', ['price_max' => 12000]],
    'no digits at all' => ['тиха квартира', ['price_min' => 10000, 'price_max' => 20000]],
]);

it('accepts prices written as plain numbers, thousands or grouped digits', function (string $query, int $price): void {
    [$filters, $dropped] = ground(['price_max' => $price], $query);

    expect($filters['price_max'])->toBe($price)->and($dropped)->toBe([]);
})->with([
    ['до 12000 грн', 12000],
    ['до 25 тисяч', 25000],
    ['до 20k', 20000],
    ['до 20 000 гривень', 20000],
]);

it('turns a lone lower bound into an upper bound when the user said "до"', function (array $llm): void {
    [$filters, $dropped] = ground($llm, 'Голосіївський до 15 тисяч');

    expect($filters['price_min'])->toBeNull()
        ->and($filters['price_max'])->toBe(15000)
        ->and($dropped)->toContain('price_min');
})->with([
    'only min' => [['price_min' => 15000, 'price_max' => null]],
    'min equals max' => [['price_min' => 15000, 'price_max' => 15000]],
]);

it('keeps a genuine range untouched', function (): void {
    [$filters, $dropped] = ground(['price_min' => 20000, 'price_max' => 35000], '2-3 кімнати від 20 до 35 тисяч');

    expect($filters)->toMatchArray(['price_min' => 20000, 'price_max' => 35000])->and($dropped)->toBe([]);
});

it('casts numeric-string prices the validator lets through', function (): void {
    [$filters] = ground(['price_max' => '25000'], 'до 25 тисяч');

    expect($filters['price_max'])->toBe(25000);
});

it('matches stems only at the start of a word', function (array $llm, string $query): void {
    [, $dropped] = ground($llm, $query);

    expect($dropped)->not->toBe([]);
})->with([
    '"cat" inside "location"' => [['pets_allowed' => true], 'great location near the park'],
    '"кат" inside "делікатний"' => [['pets_allowed' => true], 'делікатний ремонт'],
    '"поділ" inside "розподіл"' => [['districts' => ['podilskyi']], 'зручний розподіл кімнат'],
    '"мебл" is required, not "техно-"' => [['furnished' => true], 'поруч технопарк'],
]);

it('tells the city centre from a shopping centre', function (string $query, bool $grounded): void {
    [$filters] = ground(['districts' => ['pecherskyi']], $query);

    expect($filters['districts'] === ['pecherskyi'])->toBe($grounded);
})->with([
    ['квартира в центрі Києва', true],
    ['центр міста, тихо', true],
    ['поруч торговий центр', false],
]);

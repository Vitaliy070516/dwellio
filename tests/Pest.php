<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
// TestCase уже прив'язано до всього Feature; тут лише додаємо трейт (повторний extend Pest забороняє).
pest()->use(RefreshDatabase::class)->in('Feature/Database', 'Feature/Listings', 'Feature/Search');
pest()->extend(TestCase::class)->in('Architecture');

/*
|--------------------------------------------------------------------------
| Спільні хелпери тестів
|--------------------------------------------------------------------------
| Живуть тут, а не в конкретному тест-файлі: інакше файл, що їх використовує,
| проходить лише в повному прогоні (залежить від порядку завантаження).
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listingPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Затишна 2-кімнатна на Подолі',
        'description' => 'Світла квартира біля метро, можна з котом.',
        'price_uah' => 18000,
        'rooms' => 2,
        'area_m2' => 54.5,
        'floor' => 3,
        'floors_total' => 9,
        'district' => 'podilskyi',
        'address' => 'вул. Сковороди, 10',
        'metro_station' => 'Контрактова площа',
        'metro_distance_m' => 400,
        'lat' => 50.4658,
        'lng' => 30.5154,
        'pets_allowed' => true,
        'furnished' => true,
        'status' => 'active',
    ], $overrides);
}

function fakeEmbeddingClient(): FakeEmbeddingClient
{
    $client = app(EmbeddingClient::class);
    assert($client instanceof FakeEmbeddingClient);

    return $client;
}

function fakeLlm(): FakeStructuredOutputClient
{
    $client = app(StructuredOutputClient::class);
    assert($client instanceof FakeStructuredOutputClient);

    return $client;
}

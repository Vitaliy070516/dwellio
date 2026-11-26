<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Services\FakeEmbeddingClient;
use App\Modules\AI\Services\FakeStructuredOutputClient;
use App\Modules\AI\Services\FakeTextStreamClient;
use App\Modules\Generation\Enums\AdStatus;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Generation\Models\ListingAd;
use App\Modules\Generation\Prompts\AdPrompt;
use App\Modules\Listings\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
// TestCase уже прив'язано до всього Feature; тут лише додаємо трейт (повторний extend Pest забороняє).
pest()->use(RefreshDatabase::class)->in('Feature/Database', 'Feature/Listings', 'Feature/Search', 'Feature/Generation', 'Feature/Web');
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

/*
 * Контракти AI резолвляться в Budgeted-декоратори; Fake під ними — окремий singleton.
 * Налаштовуємо Fake тут, а виклики з коду все одно йдуть через облік токенів.
 */
function fakeEmbeddingClient(): FakeEmbeddingClient
{
    return app(FakeEmbeddingClient::class);
}

function fakeLlm(): FakeStructuredOutputClient
{
    return app(FakeStructuredOutputClient::class);
}

function fakeTextStream(): FakeTextStreamClient
{
    return app(FakeTextStreamClient::class);
}

/** Вичерпати денний бюджет цілі, не роблячи жодного виклику провайдера. */
function exhaustTokenBudget(AiPurpose $purpose): void
{
    $budget = app(TokenBudget::class);
    $budget->record($purpose, $budget->usage($purpose)->dailyLimit, 0);
}

/**
 * Запис генерації напряму в БД (без Job). Токен доступу за замовчуванням — `test-token`.
 *
 * @param  array<string, mixed>  $overrides
 */
function createAd(?Listing $listing = null, array $overrides = [], string $token = 'test-token'): ListingAd
{
    $listing ??= Listing::factory()->active()->create();

    return ListingAd::query()->create(array_merge([
        'listing_id' => $listing->id,
        'platform' => Platform::Olx,
        'status' => AdStatus::Queued,
        'prompt_version' => AdPrompt::VERSION,
        'access_token_hash' => ListingAd::hashToken($token),
    ], $overrides));
}

/** Валідна відповідь моделі: заголовок, порожній рядок, текст довший за мінімум майданчика. */
function validAdText(string $title = 'Світла двокімнатна біля метро на Подолі'): string
{
    return $title."\n\n"
        .'Простора квартира з меблями у тихому дворі. До метро кілька хвилин пішки, поруч парк і магазини. '
        .'Можна з домашніми тваринами. Чудовий варіант для пари або невеликої родини.';
}

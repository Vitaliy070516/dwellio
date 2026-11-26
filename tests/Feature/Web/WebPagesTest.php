<?php

declare(strict_types=1);

use App\Modules\Listings\Models\Listing;

/*
 * Демо-фронт. Логіку станів (деградація, обрив WebSocket) перевіряють JS-тести (Vitest + jsdom,
 * tests/js, `make test-js`) на мінімальних фікстурах розмітки. Тут — контракт сторінок:
 * що вони віддаються, що не розкривають зайвого, що недовірений текст екранується
 * і що фікстури JS-тестів не розійшлися зі справжнім Blade.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

it('renders the search page shell with the card template and district labels', function (): void {
    $html = $this->get('/')
        ->assertOk()
        ->assertSee('id="search-form"', false)
        ->assertSee('id="listing-card"', false)
        ->assertSee('id="search-notices"', false)
        ->getContent();

    preg_match('#<script type="application/json" id="districts-data">(.*?)</script>#s', (string) $html, $m);

    expect(json_decode($m[1] ?? '', true))->toMatchArray(['podilskyi' => 'Подільський'])->toHaveCount(10);
});

it('renders a listing card with generation buttons for every platform', function (): void {
    $listing = Listing::factory()->active()->create([
        'title' => 'Світла двокімнатна на Подолі',
        'price_uah' => 18000,
        'metro_station' => 'Контрактова площа',
        'metro_distance_m' => 400,
    ]);

    $this->get("/listings/{$listing->id}")
        ->assertOk()
        ->assertSee('Світла двокімнатна на Подолі')
        ->assertSee('18 000')
        ->assertSee('Контрактова площа · 400 м')
        ->assertSee('data-listing-id="'.$listing->id.'"', false)
        ->assertSee('data-platform="olx"', false)
        ->assertSee('data-platform="rieltor"', false)
        ->assertSee('id="ws-state"', false);
});

it('escapes listing text on the card page', function (): void {
    $listing = Listing::factory()->active()->create([
        'title' => '<script>alert(1)</script>',
        'description' => '<img src=x onerror=alert(1)>',
    ]);

    $this->get("/listings/{$listing->id}")
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('hides drafts, archived and deleted listings', function (string $state): void {
    $listing = match ($state) {
        'draft' => Listing::factory()->draft()->create(),
        'archived' => Listing::factory()->archived()->create(),
        'soft-deleted' => tap(Listing::factory()->active()->create())->delete(),
    };

    $this->get("/listings/{$listing->id}")->assertNotFound();
})->with(['draft', 'archived', 'soft-deleted']);

it('returns 404 for a listing that does not exist', function (): void {
    $this->get('/listings/999999')->assertNotFound();
});

it('redirects the old Day 6 demo page to search', function (): void {
    $this->get('/ads/demo')->assertRedirect('/');
});

/*
 * JS-тести ганяють search.js / ad-generation.js на фікстурах tests/js/fixtures. Якщо в Blade
 * перейменувати id чи name, JS на сторінці зламається, а JS-тести лишаться зеленими.
 * Цей тест закриває розрив: кожен id, name, data-field і data-platform фікстури є в справжній сторінці.
 */
it('keeps the JS test fixtures in sync with the real pages (JS DOM contract)', function (string $fixture, Closure $url): void {
    $page = (string) $this->get($url())->assertOk()->getContent();
    $html = (string) file_get_contents(base_path("tests/js/fixtures/{$fixture}.html"));

    preg_match_all('/(?<![\w-])(id|name|data-field|data-platform)="([^"]+)"/', $html, $matches, PREG_SET_ORDER);
    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $attribute, $value]) {
        expect($page)->toContain(sprintf('%s="%s"', $attribute, $value));
    }
})->with([
    'search' => ['search', fn (): string => '/'],
    'listing' => ['listing', fn (): string => '/listings/'.Listing::factory()->active()->create()->id],
]);

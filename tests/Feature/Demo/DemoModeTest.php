<?php

declare(strict_types=1);

use App\Modules\Listings\Models\Listing;
use App\Providers\AppServiceProvider;
use App\Shared\Http\Middleware\ReadOnlyInDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

function enableDemo(array $limits = []): void
{
    config(['dwellio.demo.enabled' => true]);
    foreach ($limits as $key => $value) {
        config(["dwellio.demo.rate_limit.{$key}" => $value]);
    }
}

beforeEach(function (): void {
    Queue::fake();
});

describe('read-only listings', function (): void {
    it('rejects listing writes with 403 demo_read_only and leaves data untouched', function (string $method, Closure $uri): void {
        enableDemo();
        $listing = Listing::factory()->active()->create(['title' => 'Не чіпати']);

        $this->json($method, $uri($listing), listingPayload())
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'demo_read_only')
            ->assertJsonPath('detail', ReadOnlyInDemo::DETAIL);

        expect(Listing::query()->count())->toBe(1)
            ->and($listing->fresh()?->title)->toBe('Не чіпати');
        Queue::assertNothingPushed();
    })->with([
        'create' => ['POST', fn (Listing $l): string => '/api/listings'],
        'update' => ['PUT', fn (Listing $l): string => "/api/listings/{$l->id}"],
        'delete' => ['DELETE', fn (Listing $l): string => "/api/listings/{$l->id}"],
    ]);

    it('rejects before validation: an invalid body still gets 403, not 422', function (): void {
        enableDemo();

        $this->postJson('/api/listings', [])->assertForbidden()->assertJsonPath('code', 'demo_read_only');
    });

    it('keeps reading, search and ad generation open', function (): void {
        enableDemo();
        $listing = Listing::factory()->active()->create();

        $this->getJson('/api/listings')->assertOk();
        $this->getJson("/api/listings/{$listing->id}")->assertOk();
        $this->getJson('/api/search?q=квартира')->assertOk();
        $this->postJson("/api/listings/{$listing->id}/ads", ['platform' => 'olx'])->assertAccepted();
    });

    it('allows listing writes outside demo mode', function (): void {
        $this->postJson('/api/listings', listingPayload())->assertCreated();
    });
});

describe('stricter limits', function (): void {
    it('caps search per IP at the demo limit', function (): void {
        config(['dwellio.search.rate_limit.per_minute_per_ip' => 30]);
        enableDemo(['search_per_minute_per_ip' => 2]);

        $this->getJson('/api/search?q=кіт')->assertOk();
        $this->getJson('/api/search?q=кіт')->assertOk();
        $this->getJson('/api/search?q=кіт')->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');
    });

    it('never loosens a stricter normal limit', function (): void {
        config(['dwellio.search.rate_limit.per_minute_per_ip' => 1]);
        enableDemo(['search_per_minute_per_ip' => 100]);

        $this->getJson('/api/search?q=кіт')->assertOk();
        $this->getJson('/api/search?q=кіт')->assertTooManyRequests();
    });

    it('limits ad generations per IP per day only in demo mode', function (bool $demo, int $expectedStatus): void {
        config(['dwellio.generation.rate_limit.per_minute_per_ip' => 100]);
        if ($demo) {
            enableDemo(['generation_per_minute_per_ip' => 100, 'generation_per_day_per_ip' => 1]);
        }
        [$first, $second] = Listing::factory()->active()->count(2)->create();

        $this->postJson("/api/listings/{$first->id}/ads", ['platform' => 'olx'])->assertAccepted();
        $this->postJson("/api/listings/{$second->id}/ads", ['platform' => 'olx'])->assertStatus($expectedStatus);
    })->with([
        'demo' => [true, 429],
        'local' => [false, 202],
    ]);

    it('counts the per-day limit per IP: another guest is not blocked', function (): void {
        enableDemo(['generation_per_minute_per_ip' => 100, 'generation_per_day_per_ip' => 1]);
        [$first, $second] = Listing::factory()->active()->count(2)->create();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->postJson("/api/listings/{$first->id}/ads", ['platform' => 'olx'])->assertAccepted();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->postJson("/api/listings/{$second->id}/ads", ['platform' => 'olx'])->assertAccepted();
    });
});

it('forces APP_DEBUG off in demo mode even if .env says otherwise', function (): void {
    config(['dwellio.demo.enabled' => true, 'app.debug' => true]);

    (new AppServiceProvider(app()))->register();

    expect(config('app.debug'))->toBeFalse();
});

describe('client IP behind the tunnel proxy', function (): void {
    beforeEach(function (): void {
        config(['trustedproxy.proxies' => '172.29.212.10']);
        Route::get('/_test/ip', static fn (Request $request): string => (string) $request->ip());
    });

    it('takes the guest IP from X-Forwarded-For only when the request comes from the pinned proxy', function (string $remote, string $expected): void {
        $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/_test/ip')
            ->assertSeeText($expected);
    })->with([
        'from Caddy' => ['172.29.212.10', '203.0.113.7'],
        // Запит напряму на опублікований порт (шлюз Docker) не може підробити свій IP заголовком.
        'direct, spoofed header' => ['172.29.212.1', '172.29.212.1'],
    ]);

    it('sees https behind the proxy, so generated URLs do not cause mixed content', function (): void {
        Route::get('/_test/url', static fn (): string => url('/x'));

        $this->withServerVariables(['REMOTE_ADDR' => '172.29.212.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'demo.trycloudflare.com'])
            ->get('/_test/url')
            ->assertSeeText('https://demo.trycloudflare.com/x');
    });
});

describe('runtime Reverb client config', function (): void {
    it('tells the browser to connect to the same origin in demo mode', function (): void {
        config(['dwellio.reverb_client.host' => 'localhost', 'dwellio.reverb_client.port' => 8080]);
        enableDemo();
        $listing = Listing::factory()->active()->create();

        $html = $this->get("/listings/{$listing->id}")->assertOk()->getContent();

        expect(reverbMeta((string) $html))->toMatchArray(['host' => null, 'port' => null, 'scheme' => null]);
    });

    it('uses the configured host locally and never leaks the app secret', function (): void {
        config([
            'dwellio.reverb_client' => ['host' => 'localhost', 'port' => '8080', 'scheme' => 'http'],
            'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'very-secret-value',
        ]);
        $listing = Listing::factory()->active()->create();

        $html = (string) $this->get("/listings/{$listing->id}")->assertOk()->getContent();

        expect(reverbMeta($html))->toBe(['key' => 'public-key', 'host' => 'localhost', 'port' => 8080, 'scheme' => 'http'])
            ->and($html)->not->toContain('very-secret-value');
    });
});

/** @return array<string, mixed> */
function reverbMeta(string $html): array
{
    preg_match('/<meta name="reverb-config" content="([^"]+)">/', $html, $m);
    expect($m)->not->toBeEmpty();

    return json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
}

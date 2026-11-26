<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;

/*
 * У тестах BROADCAST_CONNECTION=null, а NullBroadcaster пропускає будь-кого.
 * Щоб перевірити справжню авторизацію Pusher-протоколу, вмикаємо драйвер reverb
 * і заново реєструємо канали на ньому (HTTP до Reverb тут не йде: лише підпис).
 */
beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app(BroadcastManager::class)->purge('reverb');
    require base_path('routes/channels.php');
});

function authorizeChannel(string $adId, ?string $token): TestResponse
{
    // Guard кешує користувача в межах екземпляра застосунку; кожен виклик — як новий запит.
    app('auth')->forgetGuards();

    return test()->postJson(
        '/api/broadcasting/auth',
        ['socket_id' => '1234.5678', 'channel_name' => "private-listing-ads.{$adId}"],
        $token === null ? [] : ['X-Ad-Token' => $token],
    );
}

it('signs the private channel for the holder of the ad token', function (): void {
    $ad = createAd(token: 'secret-a');

    authorizeChannel($ad->id, 'secret-a')->assertOk()->assertJsonStructure(['auth']);
});

it('refuses the channel without a token, with a wrong one, or with the token of another ad', function (): void {
    $ad = createAd(token: 'secret-a');
    createAd(token: 'secret-b');

    authorizeChannel($ad->id, null)->assertForbidden();
    authorizeChannel($ad->id, 'guess')->assertForbidden();
    authorizeChannel($ad->id, 'secret-b')->assertForbidden();
});

it('throttles channel authorization per IP, so it cannot be used to hammer the token lookup', function (): void {
    config(['dwellio.generation.rate_limit.channel_auth_per_minute_per_ip' => 2]);
    $ad = createAd(token: 'secret-a');

    authorizeChannel($ad->id, 'secret-a')->assertOk();
    authorizeChannel($ad->id, 'guess')->assertForbidden(); // невдалі спроби теж рахуються
    authorizeChannel($ad->id, 'secret-a')->assertTooManyRequests()->assertHeader('Retry-After');
});

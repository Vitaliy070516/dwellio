<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;

it('counts tokens of calls made through the container-bound clients', function (): void {
    app(EmbeddingClient::class)->embed('тиха квартира з котом');

    expect(app(TokenBudget::class)->usage(AiPurpose::Embeddings)->calls)->toBe(1)
        ->and(app(TokenBudget::class)->usage(AiPurpose::Embeddings)->inputTokens)->toBeGreaterThan(0);
});

it('reads daily limits per purpose from config', function (): void {
    config(['dwellio.ai.budget.daily_tokens.generation' => 1234]);
    app()->forgetInstance(TokenBudget::class);

    expect(app(TokenBudget::class)->usage(AiPurpose::Generation)->dailyLimit)->toBe(1234);
});

it('shows today\'s usage against the budgets', function (): void {
    app(TokenBudget::class)->record(AiPurpose::Generation, 2400, 600);

    $this->artisan('ai:usage')
        ->expectsOutputToContain('AI token usage for')
        ->expectsTable(
            ['purpose', 'calls', 'input', 'output', 'total', 'daily limit', 'used'],
            [
                ['embeddings', '0', '0', '0', '0', '2,000,000', '0.0%'],
                ['query_parsing', '0', '0', '0', '0', '500,000', '0.0%'],
                ['generation', '1', '2,400', '600', '3,000', '1,000,000', '0.3%'],
            ],
        )
        ->assertSuccessful();
});

it('rejects a malformed date', function (): void {
    $this->artisan('ai:usage', ['--date' => '26.11.2026'])->assertFailed();
});

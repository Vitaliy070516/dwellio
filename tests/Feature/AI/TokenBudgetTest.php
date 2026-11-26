<?php

declare(strict_types=1);

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\Enums\AiPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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
            ['purpose', 'calls', 'input', 'output', 'total', 'daily limit', 'used', 'cost'],
            [
                ['embeddings', '0', '0', '0', '0', '2,000,000', '0.0%', '$0.0000'],
                ['query_parsing', '0', '0', '0', '0', '500,000', '0.0%', '$0.0000'],
                ['generation', '1', '2,400', '600', '3,000', '1,000,000', '0.3%', '$0.0000'],
            ],
        )
        ->assertSuccessful();
});

it('prices calls by the configured production model and shows spend against the caps', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC'));
    config([
        'dwellio.ai.generation.driver' => 'laravel-ai',
        'dwellio.ai.generation.provider' => 'anthropic',
        'dwellio.ai.generation.model' => 'claude-sonnet-5-5',
        'dwellio.ai.budget.daily_usd' => 0.50,
        'dwellio.ai.budget.monthly_usd' => 5,
    ]);
    app()->forgetInstance(TokenBudget::class);

    // 2 400 × $2 + 600 × $10 = 10 800 µ$ = $0.0108
    app(TokenBudget::class)->record(AiPurpose::Generation, 2400, 600);

    $this->artisan('ai:usage')
        ->expectsTable(
            ['purpose', 'calls', 'input', 'output', 'total', 'daily limit', 'used', 'cost'],
            [
                ['embeddings', '0', '0', '0', '0', '2,000,000', '0.0%', '$0.0000'],
                ['query_parsing', '0', '0', '0', '0', '500,000', '0.0%', '$0.0000'],
                ['generation', '1', '2,400', '600', '3,000', '1,000,000', '0.3%', '$0.0108'],
            ],
        )
        ->expectsTable(['spend', 'cost', 'cap', 'used'], [
            ['day 2026-11-26', '$0.0108', '$0.5000', '2.2%'],
            ['month 2026-11', '$0.0108', '$5.0000', '0.2%'],
        ])
        ->assertSuccessful();

    CarbonImmutable::setTestNow();
});

it('warns once when a paid model has no price and charges the fallback', function (): void {
    Log::spy();
    config([
        'dwellio.ai.llm.driver' => 'laravel-ai',
        'dwellio.ai.llm.provider' => 'anthropic',
        'dwellio.ai.llm.model' => 'claude-unknown',
    ]);
    app()->forgetInstance(TokenBudget::class);

    app(TokenBudget::class)->record(AiPurpose::QueryParsing, 1000, 0);
    app(TokenBudget::class)->record(AiPurpose::QueryParsing, 1000, 0);

    expect(app(TokenBudget::class)->usage(AiPurpose::QueryParsing)->costMicroUsd)->toBe(8000);
    Log::shouldHaveReceived('warning')->once();
});

it('rejects a malformed date', function (): void {
    $this->artisan('ai:usage', ['--date' => '26.11.2026'])->assertFailed();
});

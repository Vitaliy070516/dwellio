<?php

declare(strict_types=1);

use App\Modules\AI\DTO\ModelPrice;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Enums\BudgetLimit;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\AI\Services\CacheTokenBudget;
use App\Modules\AI\Services\PriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/** Haiku-подібна ціна для генерації, безкоштовні ембединги (Ollama), великий токенний ліміт. */
function spendBudget(int $dailyCapMicro = 1_000_000, int $monthlyCapMicro = 10_000_000, ?Repository $cache = null): CacheTokenBudget
{
    return new CacheTokenBudget(
        cache: $cache ?? new Repository(new ArrayStore),
        dailyLimits: ['embeddings' => PHP_INT_MAX, 'query_parsing' => PHP_INT_MAX, 'generation' => PHP_INT_MAX],
        prices: [
            'generation' => new ModelPrice(1.0, 5.0),
            'query_parsing' => new ModelPrice(0.10, 0.50),
        ],
        dailySpendCapMicroUsd: $dailyCapMicro,
        monthlySpendCapMicroUsd: $monthlyCapMicro,
    );
}

afterEach(fn () => CarbonImmutable::setTestNow());

describe('ModelPrice', function (): void {
    it('turns tokens × $/1M straight into micro-dollars', function (): void {
        // 1 000 input × $1 + 200 output × $5 = $0.002 = 2 000 µ$
        expect(new ModelPrice(1.0, 5.0)->costMicroUsd(1000, 200))->toBe(2000);
    });

    it('rounds fractions of a micro-dollar up, never down', function (): void {
        expect(new ModelPrice(0.10, 0.50)->costMicroUsd(1, 0))->toBe(1)
            ->and(new ModelPrice(0.10, 0.50)->costMicroUsd(0, 0))->toBe(0);
    });

    it('ignores negative token counts', function (): void {
        expect(new ModelPrice(1.0, 1.0)->costMicroUsd(-100, -100))->toBe(0);
    });
});

describe('PriceCatalog', function (): void {
    $catalog = fn (): PriceCatalog => PriceCatalog::fromConfig([
        'free_providers' => ['ollama'],
        'models' => ['claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50]],
        'fallback' => ['input' => 4.0, 'output' => 20.0],
    ]);

    it('prices a known paid model from config', function () use ($catalog): void {
        $price = $catalog()->resolve('laravel-ai', 'anthropic', 'claude-haiku-5-5');

        expect($price->inputPerMillion)->toBe(0.10)->and($price->outputPerMillion)->toBe(0.50);
    });

    it('treats Ollama and the fake driver as free regardless of the model', function () use ($catalog): void {
        expect($catalog()->resolve('laravel-ai', 'ollama', 'qwen2.5:3b')->isFree())->toBeTrue()
            ->and($catalog()->resolve('fake', 'anthropic', 'claude-sonnet-5-5')->isFree())->toBeTrue()
            ->and($catalog()->knows('laravel-ai', 'ollama', 'whatever'))->toBeTrue();
    });

    it('falls back to the expensive price for an unknown paid model', function () use ($catalog): void {
        expect($catalog()->resolve('laravel-ai', 'anthropic', 'claude-new-model')->outputPerMillion)->toBe(20.0)
            ->and($catalog()->knows('laravel-ai', 'anthropic', 'claude-new-model'))->toBeFalse();
    });
});

it('records cost per purpose next to the tokens', function (): void {
    $budget = spendBudget();

    $budget->record(AiPurpose::Generation, 1000, 200);
    $budget->record(AiPurpose::Embeddings, 50_000, 0);

    expect($budget->usage(AiPurpose::Generation)->costMicroUsd)->toBe(2000)
        ->and($budget->usage(AiPurpose::Embeddings)->costMicroUsd)->toBe(0)
        ->and($budget->spend()->dailyMicroUsd)->toBe(2000)
        ->and($budget->spend()->monthlyMicroUsd)->toBe(2000);
});

it('blocks every paid purpose once the shared daily cap is reached', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 23:00:00', 'UTC'));
    $budget = spendBudget(dailyCapMicro: 2000);

    // Генерація витратила денну стелю → парсинг запиту (інша ціль, свій токенний ліміт) теж зупинено.
    $budget->record(AiPurpose::Generation, 1000, 200);

    try {
        $budget->ensureAvailable(AiPurpose::QueryParsing);
        $this->fail('Expected the daily spend cap to block the call.');
    } catch (TokenBudgetExceededException $e) {
        expect($e->limit)->toBe(BudgetLimit::DailySpend)
            ->and($e->purpose)->toBe(AiPurpose::QueryParsing)
            ->and($e->retryAfterSeconds)->toBe(3600);
    }
});

it('never blocks free calls by the money cap (local Ollama keeps working)', function (): void {
    $budget = spendBudget(dailyCapMicro: 0, monthlyCapMicro: 0);

    $budget->ensureAvailable(AiPurpose::Embeddings);

    expect(fn () => $budget->ensureAvailable(AiPurpose::Generation))
        ->toThrow(TokenBudgetExceededException::class, 'Monthly AI spend cap');
});

it('keeps the monthly cap across days and resets it on the 1st (UTC)', function (): void {
    $cache = new Repository(new ArrayStore);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-29 12:00:00', 'UTC'));
    spendBudget(monthlyCapMicro: 4000, cache: $cache)->record(AiPurpose::Generation, 1000, 200);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 22:00:00', 'UTC'));
    $budget = spendBudget(monthlyCapMicro: 4000, cache: $cache);
    $budget->record(AiPurpose::Generation, 1000, 200);

    expect($budget->spend()->dailyMicroUsd)->toBe(2000)
        ->and($budget->spend()->monthlyMicroUsd)->toBe(4000);

    try {
        $budget->ensureAvailable(AiPurpose::Generation);
        $this->fail('Expected the monthly spend cap to block the call.');
    } catch (TokenBudgetExceededException $e) {
        expect($e->limit)->toBe(BudgetLimit::MonthlySpend)
            ->and($e->retryAfterSeconds)->toBe(7200);
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 00:00:01', 'UTC'));
    $budget->ensureAvailable(AiPurpose::Generation);
    expect($budget->spend('2026-11-30')->monthlyMicroUsd)->toBe(4000);
});

it('checks the per-purpose token limit before the money caps', function (): void {
    $budget = new CacheTokenBudget(
        cache: new Repository(new ArrayStore),
        dailyLimits: ['generation' => 10],
        prices: ['generation' => new ModelPrice(1.0, 1.0)],
        dailySpendCapMicroUsd: 1,
        monthlySpendCapMicroUsd: 1,
    );
    $budget->record(AiPurpose::Generation, 10, 0);

    expect(fn () => $budget->ensureAvailable(AiPurpose::Generation))
        ->toThrow(TokenBudgetExceededException::class, 'token budget');
});

it('reports which purposes cost money', function (): void {
    $budget = spendBudget();

    // Ціни немає (Ollama / Fake) → безкоштовно; ціна є → платно.
    expect($budget->isPaid(AiPurpose::Generation))->toBeTrue()
        ->and($budget->isPaid(AiPurpose::QueryParsing))->toBeTrue()
        ->and($budget->isPaid(AiPurpose::Embeddings))->toBeFalse();
});

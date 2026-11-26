<?php

declare(strict_types=1);

use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\AI\Services\CacheTokenBudget;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function tokenBudget(int $limit = 1000, ?Repository $cache = null): CacheTokenBudget
{
    return new CacheTokenBudget($cache ?? new Repository(new ArrayStore), [
        'embeddings' => $limit,
        'query_parsing' => $limit,
        'generation' => $limit,
    ]);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('accumulates input, output and calls for the day', function (): void {
    $budget = tokenBudget();

    $budget->record(AiPurpose::Generation, 100, 250);
    $budget->record(AiPurpose::Generation, 50, 0);

    $usage = $budget->usage(AiPurpose::Generation);

    expect($usage->inputTokens)->toBe(150)
        ->and($usage->outputTokens)->toBe(250)
        ->and($usage->calls)->toBe(2)
        ->and($usage->total())->toBe(400)
        ->and($usage->remaining())->toBe(600)
        ->and($usage->exhausted())->toBeFalse();
});

it('keeps budgets of different purposes apart (a reindex cannot starve generation)', function (): void {
    $budget = tokenBudget(100);

    $budget->record(AiPurpose::Embeddings, 100, 0);

    expect(fn () => $budget->ensureAvailable(AiPurpose::Embeddings))->toThrow(TokenBudgetExceededException::class);
    $budget->ensureAvailable(AiPurpose::Generation);
    $budget->ensureAvailable(AiPurpose::QueryParsing);
});

it('blocks once the limit is reached, not only once it is exceeded', function (): void {
    $budget = tokenBudget(100);
    $budget->record(AiPurpose::QueryParsing, 60, 39);
    $budget->ensureAvailable(AiPurpose::QueryParsing);

    $budget->record(AiPurpose::QueryParsing, 1, 0);

    expect(fn () => $budget->ensureAvailable(AiPurpose::QueryParsing))
        ->toThrow(TokenBudgetExceededException::class, 'query_parsing');
});

it('tells the caller how long until the counter resets at midnight UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 23:59:00', 'UTC'));
    $budget = tokenBudget(10);
    $budget->record(AiPurpose::Generation, 10, 0);

    try {
        $budget->ensureAvailable(AiPurpose::Generation);
        $this->fail('Expected the budget to be exhausted.');
    } catch (TokenBudgetExceededException $e) {
        expect($e->purpose)->toBe(AiPurpose::Generation)
            ->and($e->retryAfterSeconds)->toBe(60);
    }
});

it('starts every UTC day from zero but keeps yesterday readable', function (): void {
    $cache = new Repository(new ArrayStore);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 23:00:00', 'UTC'));
    tokenBudget(10, $cache)->record(AiPurpose::Generation, 10, 0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 00:00:01', 'UTC'));
    $budget = tokenBudget(10, $cache);

    $budget->ensureAvailable(AiPurpose::Generation);
    expect($budget->usage(AiPurpose::Generation)->total())->toBe(0)
        ->and($budget->usage(AiPurpose::Generation, '2026-11-26')->total())->toBe(10);
});

it('ignores negative token counts from a misbehaving provider', function (): void {
    $budget = tokenBudget();

    $budget->record(AiPurpose::Embeddings, -500, -1);

    expect($budget->usage(AiPurpose::Embeddings)->total())->toBe(0)
        ->and($budget->usage(AiPurpose::Embeddings)->calls)->toBe(1);
});

it('treats a missing limit as zero, so a typo in config fails closed', function (): void {
    $budget = new CacheTokenBudget(new Repository(new ArrayStore), []);

    $budget->ensureAvailable(AiPurpose::Generation);
})->throws(TokenBudgetExceededException::class);

<?php

declare(strict_types=1);

arch('AI contracts are interfaces')
    ->expect('App\Modules\AI\Contracts')
    ->toBeInterfaces();

arch('AI embedding services implement the embedding contract')
    ->expect([
        'App\Modules\AI\Services\FakeEmbeddingClient',
        'App\Modules\AI\Services\LaravelAiEmbeddingClient',
        'App\Modules\AI\Services\BudgetedEmbeddingClient',
    ])
    ->toImplement('App\Modules\AI\Contracts\EmbeddingClient');

arch('AI structured-output services implement the structured-output contract')
    ->expect([
        'App\Modules\AI\Services\FakeStructuredOutputClient',
        'App\Modules\AI\Services\LaravelAiStructuredOutputClient',
        'App\Modules\AI\Services\BudgetedStructuredOutputClient',
    ])
    ->toImplement('App\Modules\AI\Contracts\StructuredOutputClient');

arch('AI text-stream services implement the text-stream contract')
    ->expect([
        'App\Modules\AI\Services\FakeTextStreamClient',
        'App\Modules\AI\Services\LaravelAiTextStreamClient',
        'App\Modules\AI\Services\BudgetedTextStreamClient',
    ])
    ->toImplement('App\Modules\AI\Contracts\TextStreamClient');

arch('AI enums are backed enums')
    ->expect('App\Modules\AI\Enums')
    ->toBeEnums();

arch('AI DTOs are immutable')
    ->expect('App\Modules\AI\DTO')
    ->toBeFinal()
    ->toBeReadonly();

arch('AI exceptions are runtime exceptions')
    ->expect('App\Modules\AI\Exceptions')
    ->toExtend(RuntimeException::class);

arch('Only the AI adapter talks to the laravel/ai SDK')
    ->expect('Laravel\Ai')
    ->toOnlyBeUsedIn('App\Modules\AI\Services');

arch('Token accounting has a single Redis-backed implementation behind the contract')
    ->expect('App\Modules\AI\Services\CacheTokenBudget')
    ->toImplement('App\Modules\AI\Contracts\TokenBudget')
    ->toBeFinal();

arch('Budget decorators are the only place that records token usage')
    ->expect('App\Modules\AI\Contracts\TokenBudget')
    ->toOnlyBeUsedIn([
        'App\Modules\AI',
        // Ранній 503 до постановки Job у чергу (див. RequestListingAd).
        'App\Modules\Generation\Actions',
    ]);

<?php

declare(strict_types=1);

arch('AI contracts are interfaces')
    ->expect('App\Modules\AI\Contracts')
    ->toBeInterfaces();

arch('AI services implement the embedding contract')
    ->expect('App\Modules\AI\Services')
    ->toImplement('App\Modules\AI\Contracts\EmbeddingClient');

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

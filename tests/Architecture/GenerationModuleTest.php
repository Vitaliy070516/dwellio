<?php

declare(strict_types=1);

/*
 * Модуль Generation: залежить від Listings (дані квартири) і контрактів AI, але не від SDK.
 */

arch('Generation talks to the LLM only through AI contracts, DTOs, enums and exceptions')
    ->expect('App\Modules\Generation')
    ->not->toUse('App\Modules\AI\Services');

arch('Generation actions know nothing about HTTP')
    ->expect('App\Modules\Generation\Actions')
    ->not->toUse(['Illuminate\Http', 'Illuminate\Foundation\Http', 'App\Modules\Generation\Http']);

arch('Generation DTOs are immutable')
    ->expect('App\Modules\Generation\DTO')
    ->toBeFinal()
    ->toBeReadonly();

arch('Generation enums are backed enums')
    ->expect('App\Modules\Generation\Enums')
    ->toBeEnums();

arch('Generation jobs are queued and know nothing about HTTP')
    ->expect('App\Modules\Generation\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->not->toUse(['Illuminate\Http', 'App\Modules\Generation\Http']);

arch('Generation events are broadcast synchronously from the worker')
    ->expect('App\Modules\Generation\Events')
    ->toImplement('Illuminate\Contracts\Broadcasting\ShouldBroadcastNow');

arch('Generation controllers are thin: no direct DB facade')
    ->expect('App\Modules\Generation\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

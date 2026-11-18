<?php

declare(strict_types=1);

/*
 * Межі модульного моноліту (ADR-001). Ці тести — "виконувана документація":
 * зламав межу модуля — CI червоний.
 */

arch('Search does not depend on Generation')
    ->expect('App\Modules\Search')
    ->not->toUse('App\Modules\Generation');

arch('Generation does not depend on Search')
    ->expect('App\Modules\Generation')
    ->not->toUse('App\Modules\Search');

arch('Listings is a base module and depends on no other module')
    ->expect('App\Modules\Listings')
    ->not->toUse(['App\Modules\Search', 'App\Modules\Generation']);

arch('AI module does not depend on domain modules')
    ->expect('App\Modules\AI')
    ->not->toUse(['App\Modules\Listings', 'App\Modules\Search', 'App\Modules\Generation']);

arch('LLM SDK calls are isolated in the AI module')
    ->expect(['Prism', 'Laravel\Ai', 'Anthropic', 'OpenAI'])
    ->not->toBeUsedIn([
        'App\Modules\Listings',
        'App\Modules\Search',
        'App\Modules\Generation',
        'App\Shared',
    ]);

arch('Shared does not depend on modules')
    ->expect('App\Shared')
    ->not->toUse('App\Modules');

arch('Modules use strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('No debug functions left in code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

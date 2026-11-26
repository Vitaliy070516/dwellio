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

/*
 * App\Web — шар демо-фронту: компонує модулі (картка квартири + кнопки генерації), тож може залежати від усіх.
 * Зворотна залежність заборонена: модулі не знають, хто їх показує.
 */
arch('No module depends on the web layer')
    ->expect('App\Modules')
    ->not->toUse('App\Web');

arch('Web layer is thin: no DB facade, no AI services, no actions with side effects')
    ->expect('App\Web')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'App\Modules\AI', 'App\Modules\Generation\Actions', 'App\Modules\Listings\Actions']);

/*
 * Помилки API (День 11): доменні винятки не знають про HTTP — їх ловлять і Job-и, і консольні команди.
 * Переклад «доменний виняток → ApiProblem (статус + code)» робить лише HTTP-шар модуля.
 */
arch('Domain exceptions are HTTP-agnostic')
    ->expect(['App\Modules\AI\Exceptions', 'App\Modules\Generation\Exceptions'])
    ->not->toExtend('Symfony\Component\HttpKernel\Exception\HttpException')
    ->not->toUse('App\Shared\Http');

arch('ApiProblem is thrown only by the HTTP layer')
    ->expect('App\Shared\Http\Problems\ApiProblem')
    ->toOnlyBeUsedIn([
        'App\Shared\Http',
        'App\Shared\OpenApi',
        'App\Modules\Listings\Http',
        'App\Modules\Search\Http',
        'App\Modules\Generation\Http',
    ]);

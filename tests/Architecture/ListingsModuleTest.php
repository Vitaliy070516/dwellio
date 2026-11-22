<?php

declare(strict_types=1);

/*
 * Внутрішні шари модуля Listings: контролер → Action → модель. Зворотних залежностей немає.
 */

arch('Listings actions know nothing about HTTP')
    ->expect('App\Modules\Listings\Actions')
    ->not->toUse(['Illuminate\Http', 'Illuminate\Foundation\Http', 'App\Modules\Listings\Http']);

arch('Listings DTOs are immutable and framework-free')
    ->expect('App\Modules\Listings\DTO')
    ->toBeFinal()
    ->toBeReadonly()
    ->not->toUse(['Illuminate', 'App\Modules\Listings\Models']);

arch('Listings enums are backed enums')
    ->expect('App\Modules\Listings\Enums')
    ->toBeEnums();

arch('Listings models do not depend on the HTTP layer')
    ->expect('App\Modules\Listings\Models')
    ->not->toUse('App\Modules\Listings\Http');

arch('Listings controllers are thin: no direct DB facade')
    ->expect('App\Modules\Listings\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;

// Стрім генерації оголошення. «Користувач» guard-а ad-token — сама генерація (id = UUID),
// тож токен однієї генерації не відкриває канал іншої.
Broadcast::channel(
    'listing-ads.{adId}',
    static fn (Authenticatable $user, string $adId): bool => $user->getAuthIdentifier() === $adId,
    ['guards' => ['ad-token']],
);

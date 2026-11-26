<?php

declare(strict_types=1);

namespace App\Modules\Generation\DTO;

use App\Modules\Generation\Models\ListingAd;

/** Результат запиту генерації. `accessToken` існує лише тут: у БД — тільки його хеш. */
final readonly class RequestedAd
{
    public function __construct(
        public ListingAd $ad,
        #[\SensitiveParameter]
        public string $accessToken,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Generation\Enums;

enum AdStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Активні статуси покриває частковий унікальний індекс listing_ads_one_active_idx. */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Queued->value, self::Processing->value];
    }
}

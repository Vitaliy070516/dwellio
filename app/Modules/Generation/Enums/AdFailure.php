<?php

declare(strict_types=1);

namespace App\Modules\Generation\Enums;

/** Машиночитні причини збою: їх бачить клієнт і по них будуються метрики в Loki. */
enum AdFailure: string
{
    /** LLM недоступна, таймаут, обрив стріму — після всіх повторів. */
    case ProviderUnavailable = 'provider_unavailable';
    /** Модель відповіла, але текст не пройшов перевірку формату або обрізаний (length). */
    case InvalidOutput = 'invalid_output';
    /** Job перевищив $timeout. */
    case Timeout = 'timeout';
    /** Квартиру видалили або зняли з публікації, поки Job чекав у черзі. */
    case ListingUnavailable = 'listing_unavailable';
    /** Не вдалося поставити Job у RabbitMQ: без Job результату не буде. */
    case QueueUnavailable = 'queue_unavailable';
    /** Запис завис у queued/processing довше за ліміт (воркер помер). */
    case Stale = 'stale';
    case Unknown = 'unknown';
}

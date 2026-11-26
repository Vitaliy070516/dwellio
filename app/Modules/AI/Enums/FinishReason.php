<?php

declare(strict_types=1);

namespace App\Modules\AI\Enums;

/**
 * Чому модель зупинилась. Успіхом вважається лише `Stop`: `Length` означає обрізаний текст,
 * а `Unknown` трапляється, коли з'єднання закрилось без фінального пакета (обрив стріму).
 */
enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Unknown = 'unknown';

    /** Невідомі SDK-значення (tool_calls, cancelled...) для нас — не успіх. */
    public static function fromProvider(string $reason): self
    {
        return self::tryFrom($reason) ?? self::Unknown;
    }
}

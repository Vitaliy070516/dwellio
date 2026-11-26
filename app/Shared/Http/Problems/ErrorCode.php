<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use Symfony\Component\HttpFoundation\Response;

/**
 * Каталог машиночитних кодів помилок API (поле `code` у Problem Details, RFC 9457).
 *
 * Це частина публічного контракту, а не внутрішня деталь модуля, тому живе в Shared і потрапляє
 * в OpenAPI як enum. Клієнт робить `switch` за `code`, а не за текстом `detail` чи за HTTP-статусом:
 * один статус 503 означає і «бюджет AI вичерпано», і «черга недоступна».
 * Нове значення — лише додаванням (видалення чи перейменування ламає клієнтів).
 */
enum ErrorCode: string
{
    /** Тіло чи query-параметри не пройшли валідацію; подробиці по полях у `errors`. */
    case ValidationFailed = 'validation_failed';

    /** Ресурсу немає або він не публічний (чернетка, чужа генерація без токена). */
    case NotFound = 'not_found';

    case MethodNotAllowed = 'method_not_allowed';

    case Unauthenticated = 'unauthenticated';

    case Forbidden = 'forbidden';

    /** Публічне демо (`DEMO_MODE`) працює лише на читання: запис квартир вимкнено. */
    case DemoReadOnly = 'demo_read_only';

    /** Ліміт запитів на IP або глобальний денний ліміт; чекати `Retry-After` секунд. */
    case RateLimited = 'rate_limited';

    /** Для цієї квартири й майданчика вже йде генерація. */
    case AdGenerationInProgress = 'ad_generation_in_progress';

    /** Вичерпано спільний бюджет AI (токени або $); `Retry-After` — до скидання лічильника. */
    case AiBudgetExhausted = 'ai_budget_exhausted';

    /** Job генерації не вдалося поставити в чергу (RabbitMQ недоступний); можна повторити пізніше. */
    case QueueUnavailable = 'queue_unavailable';

    /** Інша HTTP-помилка без окремого коду (див. `status`). */
    case HttpError = 'http_error';

    /** Непередбачена помилка сервера; деталі лише в логах. */
    case ServerError = 'server_error';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::MethodNotAllowed => Response::HTTP_METHOD_NOT_ALLOWED,
            self::Unauthenticated => Response::HTTP_UNAUTHORIZED,
            self::Forbidden, self::DemoReadOnly => Response::HTTP_FORBIDDEN,
            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
            self::AdGenerationInProgress => Response::HTTP_CONFLICT,
            self::AiBudgetExhausted, self::QueueUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::HttpError => Response::HTTP_BAD_REQUEST,
            self::ServerError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /** Відповідь з цим кодом завжди несе `Retry-After` (секунди). */
    public function hasRetryAfter(): bool
    {
        return $this === self::RateLimited || $this === self::AiBudgetExhausted;
    }

    /** Код для помилки фреймворку, яка прийшла лише зі статусом (abort(404), throttle, 405…). */
    public static function forStatus(int $status): self
    {
        return match ($status) {
            Response::HTTP_UNPROCESSABLE_ENTITY => self::ValidationFailed,
            Response::HTTP_NOT_FOUND => self::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED => self::MethodNotAllowed,
            Response::HTTP_UNAUTHORIZED => self::Unauthenticated,
            Response::HTTP_FORBIDDEN => self::Forbidden,
            Response::HTTP_TOO_MANY_REQUESTS => self::RateLimited,
            default => $status >= 500 ? self::ServerError : self::HttpError,
        };
    }

    /** RFC 9457 `type`: URN, а не URL, бо окремої сторінки на кожен код немає (і не залежить від хоста). */
    public function type(): string
    {
        return 'urn:dwellio:problem:'.$this->value;
    }
}

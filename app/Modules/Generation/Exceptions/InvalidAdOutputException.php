<?php

declare(strict_types=1);

namespace App\Modules\Generation\Exceptions;

use RuntimeException;

/**
 * Модель відповіла, але текст не годиться: немає заголовка/тексту, завеликий або обрізаний (length).
 * LLM недетермінована, тож Job повторює спробу, але кількість спроб обмежена ($maxExceptions).
 */
final class InvalidAdOutputException extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/** Тимчасовий збій провайдера (мережа, таймаут, 5xx, ліміти): повторна спроба має сенс. */
final class EmbeddingFailedException extends RuntimeException {}

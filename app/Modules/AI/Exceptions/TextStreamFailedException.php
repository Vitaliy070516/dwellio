<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/**
 * Стрім не відбувся або обірвався: таймаут, 5xx, помилка в самому потоці.
 * Вважається тимчасовим збоєм: Job може повторити спробу.
 */
final class TextStreamFailedException extends RuntimeException {}

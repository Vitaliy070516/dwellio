<?php

declare(strict_types=1);

namespace App\Modules\Generation\Exceptions;

use RuntimeException;

/** Для цієї квартири й майданчика вже йде генерація (HTTP 409). */
final class AdGenerationInProgressException extends RuntimeException {}

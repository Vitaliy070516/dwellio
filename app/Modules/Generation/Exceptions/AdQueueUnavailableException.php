<?php

declare(strict_types=1);

namespace App\Modules\Generation\Exceptions;

use RuntimeException;

/**
 * Job не вдалося поставити в чергу (HTTP 503). На відміну від ембедингів (їх дозорахує reindex),
 * без Job оголошення не з'явиться ніколи, тож мовчати не можна.
 */
final class AdQueueUnavailableException extends RuntimeException {}

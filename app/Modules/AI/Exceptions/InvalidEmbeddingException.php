<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/** Постійна помилка (порожній текст, неправильна розмірність, нечислові значення): повтор не допоможе. */
final class InvalidEmbeddingException extends RuntimeException {}

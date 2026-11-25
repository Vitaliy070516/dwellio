<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/**
 * LLM не віддала валідний структурований результат: збій провайдера, таймаут
 * або JSON, який не відповідає схемі. Викликач вирішує, який fallback застосувати.
 */
final class StructuredOutputFailedException extends RuntimeException {}

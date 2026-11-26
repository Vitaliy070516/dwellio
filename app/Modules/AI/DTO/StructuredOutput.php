<?php

declare(strict_types=1);

namespace App\Modules\AI\DTO;

/** Відповідь Structured Output разом із токенами: без них облік витрат на парсинг запитів сліпий. */
final readonly class StructuredOutput
{
    /**
     * @param  array<string, mixed>  $data  відповідь моделі; НЕ довіряємо їй, викликач валідує сам
     */
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}

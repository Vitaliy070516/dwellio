<?php

declare(strict_types=1);

namespace App\Modules\Generation\DTO;

/** Перевірений результат генерації: заголовок і текст уже вкладаються в ліміти майданчика. */
final readonly class GeneratedAd
{
    public function __construct(
        public string $title,
        public string $body,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\DTO\Embedding;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use Throwable;

/**
 * Детермінований клієнт без мережі: той самий текст дає той самий вектор, різні тексти дають різні.
 * Для Pest і для локальної розробки без Ollama (AI_EMBEDDING_DRIVER=fake). Це НЕ семантичні
 * вектори: пошук за змістом із ним не працює, лише перевіряється «проводка».
 */
final class FakeEmbeddingClient implements EmbeddingClient
{
    /** @var list<string> */
    private array $embedded = [];

    private ?Throwable $nextFailure = null;

    public function __construct(
        private readonly int $dimensions,
        private readonly string $model = 'fake-embedding',
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(string $text): Embedding
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }

        if (trim($text) === '') {
            throw new InvalidEmbeddingException('Cannot embed empty text.');
        }

        $this->embedded[] = $text;

        return new Embedding($this->vectorFor($text), $this->model, inputTokens: max(1, intdiv(mb_strlen($text), 4)));
    }

    /** Наступний виклик embed() кине цей виняток (один раз): емуляція падіння провайдера. */
    public function failNextWith(Throwable $failure): self
    {
        $this->nextFailure = $failure;

        return $this;
    }

    /** @return list<string> */
    public function embeddedTexts(): array
    {
        return $this->embedded;
    }

    /**
     * @return list<float> одиничний вектор, отриманий із SHA-256 (без глобального mt_srand)
     */
    private function vectorFor(string $text): array
    {
        $values = [];
        for ($chunk = 0; count($values) < $this->dimensions; $chunk++) {
            foreach (str_split(hash('sha256', $text.'#'.$chunk, true)) as $char) {
                $values[] = ord($char) / 127.5 - 1.0;
            }
        }
        $values = array_slice($values, 0, $this->dimensions);

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $values)));

        return array_map(static fn (float $v): float => $norm > 0.0 ? $v / $norm : $v, $values);
    }
}

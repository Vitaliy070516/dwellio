<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTO\Embedding;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;

/**
 * Єдина точка входу до векторизації для решти модулів (ADR-004).
 * Реалізація (Ollama, Voyage, Fake) обирається конфігом, а не кодом викликача.
 */
interface EmbeddingClient
{
    /**
     * Модель, яка рахуватиме вектори. Потрібна ДО виклику: за нею шукаємо вже збережений
     * ембединг і вирішуємо, чи можна пропустити платний запит (ідемпотентність за хешем).
     */
    public function model(): string;

    /**
     * @throws EmbeddingFailedException якщо збій тимчасовий (мережа, 5xx, ліміти): Job може повторити спробу
     * @throws InvalidEmbeddingException якщо повтор не допоможе (порожній текст, неправильна розмірність)
     */
    public function embed(string $text): Embedding;
}

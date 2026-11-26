<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\StructuredOutputClient;
use App\Modules\AI\DTO\StructuredOutput;
use Closure;
use Throwable;

/**
 * Детермінований клієнт без мережі. За замовчуванням повертає `[]` («нічого не розпізнано»);
 * у тестах відповідь або збій задаються явно.
 */
final class FakeStructuredOutputClient implements StructuredOutputClient
{
    public const string MODEL = 'fake-structured';

    /** @var array<string, mixed> */
    private array $response = [];

    private ?Throwable $nextFailure = null;

    /** @var list<string> */
    private array $prompts = [];

    /** @param  array<string, mixed>  $response */
    public function respondWith(array $response): self
    {
        $this->response = $response;

        return $this;
    }

    /** Наступний виклик кине цей виняток (один раз): емуляція падіння провайдера. */
    public function failNextWith(Throwable $failure): self
    {
        $this->nextFailure = $failure;

        return $this;
    }

    /** @return list<string> */
    public function prompts(): array
    {
        return $this->prompts;
    }

    public function generate(string $instructions, string $prompt, Closure $schema): StructuredOutput
    {
        $this->prompts[] = $prompt;

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }

        // Грубо 4 символи на токен: досить, щоб тести обліку бачили ненульові числа.
        return new StructuredOutput($this->response, self::MODEL, max(1, intdiv(mb_strlen($prompt), 4)), count($this->response));
    }
}

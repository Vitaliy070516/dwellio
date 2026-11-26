<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Enums\FinishReason;
use Generator;
use Throwable;

/**
 * Детермінований стрім без мережі. Сценарії задаються в черзі й споживаються по одному
 * на виклик (так моделюються ретраї: перша спроба падає, друга успішна).
 * Коли сценаріїв немає — віддає валідне типове оголошення, щоб локально працювало з driver=fake.
 */
final class FakeTextStreamClient implements TextStreamClient
{
    public const string MODEL = 'fake-stream';

    public const string DEFAULT_TEXT = "Затишна квартира біля метро\n\n"
        .'Світла квартира з ремонтом і всіма необхідними меблями. '
        .'Поруч метро, магазини та парк. Тиха вулиця, привітні сусіди.';

    /** @var list<array{chunks: list<string>, reason: FinishReason, failure: ?Throwable}> */
    private array $scenarios = [];

    /** @var list<array{instructions: string, prompt: string, max_tokens: int}> */
    private array $calls = [];

    /**
     * Наступний виклик віддасть ці фрагменти й завершиться з указаною причиною.
     *
     * @param  list<string>  $chunks
     */
    public function pushResponse(array $chunks, FinishReason $reason = FinishReason::Stop): self
    {
        $this->scenarios[] = ['chunks' => $chunks, 'reason' => $reason, 'failure' => null];

        return $this;
    }

    /** Повний текст одним сценарієм, розбитий на фрагменти по `$chunkSize` символів. */
    public function pushText(string $text, int $chunkSize = 8, FinishReason $reason = FinishReason::Stop): self
    {
        return $this->pushResponse(mb_str_split($text, max(1, $chunkSize)), $reason);
    }

    /**
     * Наступний виклик віддасть `$chunksBefore` і кине виняток: обрив посеред стріму.
     *
     * @param  list<string>  $chunksBefore
     */
    public function pushFailure(Throwable $failure, array $chunksBefore = []): self
    {
        $this->scenarios[] = ['chunks' => $chunksBefore, 'reason' => FinishReason::Unknown, 'failure' => $failure];

        return $this;
    }

    /** @return list<array{instructions: string, prompt: string, max_tokens: int}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function stream(string $instructions, string $prompt, int $maxTokens): Generator
    {
        $this->calls[] = ['instructions' => $instructions, 'prompt' => $prompt, 'max_tokens' => $maxTokens];

        $scenario = array_shift($this->scenarios)
            ?? ['chunks' => mb_str_split(self::DEFAULT_TEXT, 16), 'reason' => FinishReason::Stop, 'failure' => null];

        $outputTokens = 0;

        foreach ($scenario['chunks'] as $chunk) {
            $outputTokens++;

            yield $chunk;
        }

        if ($scenario['failure'] !== null) {
            throw $scenario['failure'];
        }

        return new TextStreamResult(self::MODEL, $scenario['reason'], mb_strlen($prompt), $outputTokens);
    }
}

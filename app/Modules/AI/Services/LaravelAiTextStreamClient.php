<?php

declare(strict_types=1);

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Enums\FinishReason;
use App\Modules\AI\Exceptions\TextStreamFailedException;
use Generator;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * Адаптер над стрімінгом laravel/ai. Перетворює події SDK на прості фрагменти тексту
 * й підсумок; усі винятки SDK загортає в TextStreamFailedException, щоб викликач
 * не залежав від класів SDK.
 */
final readonly class LaravelAiTextStreamClient implements TextStreamClient
{
    public function __construct(
        private string $provider,
        private string $model,
        private int $timeout = 180,
        private float $temperature = 0.7,
    ) {}

    public function stream(string $instructions, string $prompt, int $maxTokens): Generator
    {
        $model = $this->model;
        // Без StreamEnd стрім вважаємо обірваним, навіть якщо SDK не кинув виняток.
        $reason = FinishReason::Unknown;
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            $response = (new TextStreamAgent($instructions, $maxTokens, $this->temperature))
                ->stream($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    if ($event->delta !== '') {
                        yield $event->delta;
                    }
                } elseif ($event instanceof StreamStart) {
                    $model = $event->model;
                } elseif ($event instanceof StreamEnd) {
                    $reason = FinishReason::fromProvider($event->reason);
                    $inputTokens = $event->usage->inputTokens;
                    $outputTokens = $event->usage->outputTokens;
                } elseif ($event instanceof Error) {
                    throw new TextStreamFailedException(
                        sprintf('LLM provider "%s" reported a stream error: %s', $this->provider, $event->message),
                    );
                }
            }
        } catch (TextStreamFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TextStreamFailedException(
                sprintf('LLM provider "%s" stream failed: %s', $this->provider, $e->getMessage()),
                previous: $e,
            );
        }

        return new TextStreamResult($model, $reason, $inputTokens, $outputTokens);
    }
}

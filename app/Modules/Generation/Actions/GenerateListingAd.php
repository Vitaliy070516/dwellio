<?php

declare(strict_types=1);

namespace App\Modules\Generation\Actions;

use App\Modules\AI\Contracts\TextStreamClient;
use App\Modules\AI\Contracts\TokenBudget;
use App\Modules\AI\DTO\TextStreamResult;
use App\Modules\AI\Enums\AiPurpose;
use App\Modules\AI\Enums\FinishReason;
use App\Modules\AI\Exceptions\TextStreamFailedException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\Generation\DTO\GeneratedAd;
use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Enums\AdStatus;
use App\Modules\Generation\Exceptions\InvalidAdOutputException;
use App\Modules\Generation\Models\ListingAd;
use App\Modules\Generation\Prompts\AdPrompt;
use App\Modules\Generation\Support\AdStreamPublisher;
use App\Modules\Generation\Support\AdTextParser;
use App\Modules\Generation\Support\ChunkBuffer;
use App\Modules\Listings\Models\Listing;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Одна спроба генерації: захопити запис → стрім LLM → пакети чанків у Reverb → перевірка → запис у БД.
 *
 * Тимчасові збої (TextStreamFailedException) і невдалий текст (InvalidAdOutputException)
 * летять нагору: Job повторить спробу. Exactly-once не гарантуємо: якщо воркер помер після
 * відповіді LLM, але до запису в БД, наступна спроба заплатить за токени ще раз.
 */
final readonly class GenerateListingAd
{
    public function __construct(
        private TextStreamClient $llm,
        private AdPrompt $prompt,
        private AdTextParser $parser,
        private Dispatcher $events,
        private TokenBudget $budget,
    ) {}

    /** @throws TokenBudgetExceededException бюджет скінчився, поки Job чекав у черзі */
    public function handle(string $adId): void
    {
        $ad = ListingAd::query()->find($adId);

        if ($ad === null || ! $ad->status->isActive()) {
            Log::info('ad generation skipped: not active', ['ad_id' => $adId, 'status' => $ad?->status->value]);

            return;
        }

        // find() не бачить soft-deleted; знята з публікації квартира теж не генерується.
        $listing = Listing::query()->find($ad->listing_id);

        if ($listing === null || ! $listing->status->isSearchable()) {
            $this->fail($ad->id, AdFailure::ListingUnavailable);

            return;
        }

        // До claim: якщо платити нічим, не чіпаємо attempt і не шлемо браузеру `ad.started`.
        $this->budget->ensureAvailable(AiPurpose::Generation);

        $attempt = $ad->attempt + 1;

        if (! $this->claim($ad->id, $attempt)) {
            return;
        }

        $publisher = new AdStreamPublisher(
            $this->events, $ad->id, $attempt, config()->integer('dwellio.generation.max_broadcast_failures'),
        );
        $publisher->started();

        $startedAt = hrtime(true);
        $firstTokenMs = null;
        $text = '';
        $result = null;

        try {
            $buffer = new ChunkBuffer(
                config()->integer('dwellio.generation.flush_interval_ms'),
                config()->integer('dwellio.generation.max_chunk_chars'),
            );

            $stream = $this->llm->stream(
                $this->prompt->instructions($ad->platform),
                $this->prompt->prompt($listing, $ad->platform),
                $ad->platform->maxTokens(),
            );

            foreach ($stream as $delta) {
                $firstTokenMs ??= $this->elapsedMs($startedAt);
                $text .= $delta;

                foreach ($buffer->push($delta) as $chunk) {
                    $publisher->chunk($chunk);
                }
            }

            foreach ($buffer->flush() as $chunk) {
                $publisher->chunk($chunk);
            }

            $result = $stream->getReturn();
            $generated = $this->validate($ad, $result, $text);
        } catch (Throwable $e) {
            $this->logAttempt($ad, $attempt, $e::class, $startedAt, $firstTokenMs, $publisher, $result, $e->getMessage(), mb_strlen($text));

            throw $e;
        }

        $saved = ListingAd::query()
            ->whereKey($ad->id)
            ->where('status', AdStatus::Processing)
            ->where('attempt', $attempt)
            ->incrementEach(
                ['input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens],
                [
                    'status' => AdStatus::Completed->value,
                    'title' => $generated->title,
                    'body' => $generated->body,
                    'model' => $result->model,
                    'failure_code' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ],
            );

        // 0 рядків: запис уже закрили (stale) або його перехопила новіша спроба — результат не наш.
        if ($saved === 0) {
            $this->logAttempt($ad, $attempt, 'superseded', $startedAt, $firstTokenMs, $publisher, $result);

            return;
        }

        $publisher->completed();
        $this->logAttempt($ad, $attempt, 'completed', $startedAt, $firstTokenMs, $publisher, $result);
    }

    /** Остаточний збій: викликає Job::failed() або сама дія, коли повтор не має сенсу. */
    public function fail(string $adId, AdFailure $failure): void
    {
        $updated = ListingAd::query()
            ->whereKey($adId)
            ->whereIn('status', AdStatus::activeValues())
            ->update([
                'status' => AdStatus::Failed,
                'failure_code' => $failure,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            (new AdStreamPublisher($this->events, $adId, 0, PHP_INT_MAX))->failed($failure);
        }
    }

    /** Атомарно переводить запис у processing і збільшує attempt. False — запис уже не активний. */
    private function claim(string $adId, int $attempt): bool
    {
        return ListingAd::query()
            ->whereKey($adId)
            ->whereIn('status', AdStatus::activeValues())
            ->update([
                'status' => AdStatus::Processing,
                'attempt' => $attempt,
                'started_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    private function validate(ListingAd $ad, TextStreamResult $result, string $text): GeneratedAd
    {
        if ($result->finishReason === FinishReason::Unknown) {
            // З'єднання закрилось без фінального пакета: це обрив, а не відповідь моделі.
            $this->addTokens($ad->id, $result);

            throw new TextStreamFailedException('LLM stream ended without a final event (connection dropped?).');
        }

        try {
            if (! $result->completed()) {
                throw new InvalidAdOutputException(sprintf('LLM stopped with reason "%s".', $result->finishReason->value));
            }

            return $this->parser->parse($text, $ad->platform);
        } catch (InvalidAdOutputException $e) {
            // Невдала спроба теж коштувала токенів: рахуємо їх, перш ніж повторювати.
            $this->addTokens($ad->id, $result);

            throw $e;
        }
    }

    private function addTokens(string $adId, TextStreamResult $result): void
    {
        ListingAd::query()->whereKey($adId)->incrementEach([
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);
    }

    private function elapsedMs(int|float $since): int
    {
        return (int) ((hrtime(true) - $since) / 1_000_000);
    }

    /**
     * Один JSON-рядок на спробу: з нього на тижні 3 в Loki будуються SLI
     * (p95 часу до першого токена, частка невдалих генерацій, токени за день). Без тексту оголошення.
     */
    private function logAttempt(
        ListingAd $ad,
        int $attempt,
        string $outcome,
        int|float $startedAt,
        ?int $firstTokenMs,
        AdStreamPublisher $publisher,
        ?TextStreamResult $result = null,
        ?string $error = null,
        ?int $outputChars = null,
    ): void {
        Log::info('ad generation attempt', [
            'error' => $error,
            'output_chars' => $outputChars,
            'ad_id' => $ad->id,
            'listing_id' => $ad->listing_id,
            'platform' => $ad->platform->value,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'duration_ms' => $this->elapsedMs($startedAt),
            'time_to_first_token_ms' => $firstTokenMs,
            'model' => $result?->model,
            'finish_reason' => $result?->finishReason->value,
            'input_tokens' => $result?->inputTokens,
            'output_tokens' => $result?->outputTokens,
            'chunks_sent' => $publisher->chunksSent,
            'broadcast_failures' => $publisher->failures,
        ]);
    }
}

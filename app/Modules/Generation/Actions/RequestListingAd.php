<?php

declare(strict_types=1);

namespace App\Modules\Generation\Actions;

use App\Modules\Generation\DTO\RequestedAd;
use App\Modules\Generation\Enums\AdFailure;
use App\Modules\Generation\Enums\AdStatus;
use App\Modules\Generation\Enums\Platform;
use App\Modules\Generation\Exceptions\AdGenerationInProgressException;
use App\Modules\Generation\Exceptions\AdQueueUnavailableException;
use App\Modules\Generation\Jobs\GenerateListingAdJob;
use App\Modules\Generation\Models\ListingAd;
use App\Modules\Generation\Prompts\AdPrompt;
use App\Modules\Listings\Models\Listing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Створює запис генерації й ставить Job у чергу `generation`.
 *
 * Дублікати: частковий унікальний індекс пропускає лише одну активну генерацію на квартиру
 * й майданчик, тож подвійний клік не оплачує два виклики LLM. Повернути той самий токен
 * неможливо (зберігається лише хеш), тому дублікат отримує 409, а не «той самий» запис.
 */
final readonly class RequestListingAd
{
    public function handle(Listing $listing, Platform $platform): RequestedAd
    {
        $token = Str::random(48);

        try {
            $ad = DB::transaction(function () use ($listing, $platform, $token): ListingAd {
                $this->expireStale($listing, $platform);

                return ListingAd::query()->create([
                    'listing_id' => $listing->id,
                    'platform' => $platform,
                    'status' => AdStatus::Queued,
                    'prompt_version' => AdPrompt::VERSION,
                    'access_token_hash' => ListingAd::hashToken($token),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new AdGenerationInProgressException(
                sprintf('Ad generation for listing %d on %s is already in progress.', $listing->id, $platform->value),
                previous: $e,
            );
        }

        try {
            GenerateListingAdJob::dispatch($ad->id);
        } catch (Throwable $e) {
            report($e);

            $ad->forceFill([
                'status' => AdStatus::Failed,
                'failure_code' => AdFailure::QueueUnavailable,
                'finished_at' => now(),
            ])->save();

            throw new AdQueueUnavailableException('Ad generation queue is unavailable.', previous: $e);
        }

        return new RequestedAd($ad, $token);
    }

    /**
     * Запис, що «живе» довше за retryUntil Job-а, вже ніхто не завершить (воркер помер, повідомлення втрачено).
     * Закриваємо його, щоб він не блокував нові запити через унікальний індекс.
     */
    private function expireStale(Listing $listing, Platform $platform): void
    {
        ListingAd::query()
            ->where('listing_id', $listing->id)
            ->where('platform', $platform)
            ->whereIn('status', AdStatus::activeValues())
            ->where('created_at', '<', now()->subSeconds(config()->integer('dwellio.generation.stale_after_seconds')))
            ->update([
                'status' => AdStatus::Failed,
                'failure_code' => AdFailure::Stale,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}

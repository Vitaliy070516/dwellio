<?php

declare(strict_types=1);

namespace App\Modules\Listings\Actions;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\AI\Exceptions\EmbeddingFailedException;
use App\Modules\AI\Exceptions\InvalidEmbeddingException;
use App\Modules\AI\Exceptions\TokenBudgetExceededException;
use App\Modules\Listings\Enums\EmbedOutcome;
use App\Modules\Listings\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Рахує й зберігає ембединг квартири. Ідемпотентна: повторний виклик із тим самим текстом
 * не платить за токени й не чіпає БД, а upsert за (listing_id, embedding_model) не дає дублікатів.
 */
final readonly class EmbedListing
{
    public function __construct(private EmbeddingClient $client) {}

    /**
     * @throws EmbeddingFailedException тимчасовий збій провайдера, Job повторить спробу
     * @throws InvalidEmbeddingException постійна помилка, Job зупиниться без повторів
     * @throws TokenBudgetExceededException денний бюджет вичерпано, провайдера не викликали
     */
    public function handle(Listing $listing): EmbedOutcome
    {
        // Чернетки й архів не шукаються, тож і платити за їхні вектори немає сенсу (FinOps).
        // Коли квартира стане active, PUT знову поставить Job у чергу.
        if (! $listing->status->isSearchable()) {
            return EmbedOutcome::Skipped;
        }

        $text = $listing->embeddingSource();
        $hash = hash('sha256', $text);
        $model = $this->client->model();

        $storedHash = DB::table('listing_embeddings')
            ->where('listing_id', $listing->id)
            ->where('embedding_model', $model)
            ->value('content_hash');

        if ($storedHash === $hash) {
            return EmbedOutcome::Unchanged;
        }

        $embedding = $this->client->embed($text);

        // Вектор пишемо сирим SQL: колонку `embedding` Eloquent-модель навмисно не знає.
        DB::statement(
            'INSERT INTO listing_embeddings (listing_id, embedding_model, content_hash, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?::vector, now(), now())
             ON CONFLICT (listing_id, embedding_model) DO UPDATE
             SET content_hash = EXCLUDED.content_hash, embedding = EXCLUDED.embedding, updated_at = now()',
            [$listing->id, $embedding->model, $hash, $embedding->toPgVector()],
        );

        return EmbedOutcome::Embedded;
    }
}

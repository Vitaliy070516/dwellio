<?php

declare(strict_types=1);

namespace App\Modules\Listings\Console;

use App\Modules\AI\Contracts\EmbeddingClient;
use App\Modules\Listings\Jobs\IndexListingEmbedding;
use App\Modules\Listings\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Дозорахунок ембедингів: початкове наповнення після сидера, зміна моделі, відновлення
 * після збою черги. Лише ставить Job-и в чергу, платні виклики робить воркер.
 */
final class ReindexListingEmbeddings extends Command
{
    protected $signature = 'listings:reindex
        {--all : Поставити в чергу всі активні квартири (Job сам пропустить незмінені за хешем)}';

    protected $description = 'Queue embedding jobs for active listings that have no embedding for the current model';

    public function handle(EmbeddingClient $client): int
    {
        $model = $client->model();
        $all = (bool) $this->option('all');
        $queued = 0;

        Listing::query()
            ->searchable()
            ->select('listings.id')
            ->unless($all, fn ($query) => $query->whereNotExists(
                fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('listing_embeddings')
                    ->whereColumn('listing_embeddings.listing_id', 'listings.id')
                    ->where('listing_embeddings.embedding_model', $model),
            ))
            // chunkById: пам'ять не росте разом із кількістю квартир, тексти не вантажимо.
            ->chunkById(200, function (Collection $listings) use (&$queued): void {
                foreach ($listings as $listing) {
                    IndexListingEmbedding::dispatch($listing->id);
                    $queued++;
                }
            }, 'listings.id', 'id');

        $this->info(sprintf('Queued %d listing(s) for model "%s".', $queued, $model));

        return self::SUCCESS;
    }
}

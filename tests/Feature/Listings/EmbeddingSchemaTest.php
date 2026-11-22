<?php

declare(strict_types=1);

use App\Modules\Listings\Models\Listing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Захищає припущення схеми, на яких тримається Day 4–5: розмірність, унікальність,
 * каскад і те, що pgvector справді рахує косинусну відстань.
 */

/**
 * @param  list<float>  $head  перші компоненти; решта до 1024 — нулі
 */
function vectorLiteral(array $head, int $dims = 1024): string
{
    $values = array_pad($head, $dims, 0.0);

    return '['.implode(',', $values).']';
}

function insertEmbedding(int $listingId, string $vector, string $model = 'bge-m3'): void
{
    // Вкладена транзакція = savepoint: після помилки Postgres не «отруює» зовнішню транзакцію RefreshDatabase.
    DB::transaction(static function () use ($listingId, $vector, $model): void {
        DB::insert(
            'INSERT INTO listing_embeddings (listing_id, embedding_model, content_hash, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?::vector, now(), now())',
            [$listingId, $model, str_repeat('a', 64), $vector],
        );
    });
}

it('rejects a vector with the wrong dimensionality', function (): void {
    $listing = Listing::factory()->create();

    expect(fn () => insertEmbedding($listing->id, vectorLiteral([1.0, 0.0, 0.0], dims: 3)))
        ->toThrow(QueryException::class);
});

it('allows one embedding per listing and model, but several models per listing', function (): void {
    $listing = Listing::factory()->create();

    insertEmbedding($listing->id, vectorLiteral([1.0]), model: 'bge-m3');
    insertEmbedding($listing->id, vectorLiteral([1.0]), model: 'voyage-3');

    expect(fn () => insertEmbedding($listing->id, vectorLiteral([1.0]), model: 'bge-m3'))
        ->toThrow(QueryException::class);

    expect($listing->embeddings()->count())->toBe(2);
});

it('finds the nearest neighbour by cosine distance', function (): void {
    [$near, $far] = Listing::factory()->count(2)->create();

    insertEmbedding($near->id, vectorLiteral([1.0, 0.1]));
    insertEmbedding($far->id, vectorLiteral([0.0, 1.0]));

    $nearest = DB::selectOne(
        'SELECT listing_id FROM listing_embeddings ORDER BY embedding <=> ?::vector LIMIT 1',
        [vectorLiteral([1.0, 0.0])],
    );

    expect($nearest)->not->toBeNull()
        ->and($nearest->listing_id)->toBe($near->id);
});

it('cascades embeddings on force delete but keeps them on soft delete', function (): void {
    $listing = Listing::factory()->create();
    insertEmbedding($listing->id, vectorLiteral([1.0]));

    $listing->delete();
    expect(DB::table('listing_embeddings')->count())->toBe(1);

    $listing->forceDelete();
    expect(DB::table('listing_embeddings')->count())->toBe(0);
});

it('builds the embedding source from title and description', function (): void {
    $listing = Listing::factory()->make(['title' => 'Заголовок', 'description' => 'Опис квартири']);

    expect($listing->embeddingSource())->toBe("Заголовок\nОпис квартири");
});

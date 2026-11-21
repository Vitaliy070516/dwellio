<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Розмірність фіксована: локальна (bge-m3) і прод-модель мусять давати 1024.
     */
    private const int DIMENSIONS = 1024;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('listing_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('embedding_model', 64);
            // SHA-256 тексту, з якого пораховано вектор → ідемпотентність Job-а індексації.
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['listing_id', 'embedding_model']);
        });

        // Колонку vector додаємо сирим SQL: Blueprint не знає про цей тип.
        DB::statement(sprintf('ALTER TABLE listing_embeddings ADD COLUMN embedding vector(%d) NOT NULL', self::DIMENSIONS));

        DB::statement(
            'CREATE INDEX listing_embeddings_hnsw_idx ON listing_embeddings '
            .'USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_embeddings');
    }
};

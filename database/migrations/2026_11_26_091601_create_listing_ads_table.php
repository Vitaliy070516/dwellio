<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Згенероване оголошення для майданчика. Ця таблиця — джерело істини;
        // WebSocket лише швидко доставляє проміжні події й нічого не гарантує.
        Schema::create('listing_ads', function (Blueprint $table): void {
            // UUID, а не автоінкремент: id потрапляє в назву каналу й URL, тож не має бути вгадуваним.
            $table->uuid('id')->primary();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('status', 16);
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('failure_code', 32)->nullable();
            // Номер поточної спроби: старі чанки попередньої спроби браузер відкидає за ним,
            // а фінальний запис робиться лише якщо attempt не змінився (оптимістичне блокування).
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 16);
            // Сума токенів УСІХ спроб, включно з невдалими: за них теж платимо (FinOps).
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Лише SHA-256 від токена доступу: сам токен віддається клієнту один раз.
            $table->char('access_token_hash', 64)->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'platform']);
        });

        // Не більше однієї активної генерації на квартиру й майданчик: подвійний клік
        // не запускає два платні виклики LLM. Частковий індекс — можливість саме Postgres.
        DB::statement(
            'CREATE UNIQUE INDEX listing_ads_one_active_idx ON listing_ads (listing_id, platform) '
            ."WHERE status IN ('queued', 'processing')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_ads');
    }
};

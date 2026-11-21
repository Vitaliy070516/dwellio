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
        // Розширення вже створюються init-скриптом Docker, але міграція має бути самодостатньою.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('description');

            // Жорсткі умови пошуку — окремими колонками, щоб йшли у WHERE.
            $table->unsignedInteger('price_uah');
            $table->unsignedSmallInteger('rooms');
            $table->decimal('area_m2', 6, 2);
            $table->unsignedSmallInteger('floor');
            $table->unsignedSmallInteger('floors_total');
            $table->string('district', 64);
            $table->string('address', 255);
            $table->string('metro_station', 64)->nullable();
            $table->unsignedInteger('metro_distance_m')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->boolean('pets_allowed')->default(false);
            $table->boolean('furnished')->default(false);

            // varchar + PHP enum замість native enum Postgres: простіше міняти міграціями.
            $table->string('status', 16)->default('draft');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'district', 'price_uah']);
            $table->index('rooms');
        });

        // Повнотекстовий та триграмний пошук (словника для української немає → 'simple').
        DB::statement(
            "CREATE INDEX listings_fts_idx ON listings USING gin (to_tsvector('simple', title || ' ' || description))"
        );
        DB::statement(
            "CREATE INDEX listings_trgm_idx ON listings USING gin ((title || ' ' || description) gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};

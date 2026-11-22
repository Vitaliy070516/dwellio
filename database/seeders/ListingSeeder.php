<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Listings\Models\Listing;
use Illuminate\Database\Seeder;

final class ListingSeeder extends Seeder
{
    private const int COUNT = 300;

    public function run(): void
    {
        // Ідемпотентність: повторний `db:seed` не множить квартири (для чистого стану — migrate:fresh --seed).
        if (Listing::query()->exists()) {
            $this->command?->warn('Listings already seeded, skipping.');

            return;
        }

        // Фіксований seed → однаковий набір даних у всіх, зручно для демо й відтворюваних багів.
        fake()->seed(2026);

        // Пакетами по 100: не тримаємо в пам'яті всі моделі одразу.
        foreach (range(1, self::COUNT / 100) as $_) {
            Listing::factory()->count(100)->create();
        }
    }
}

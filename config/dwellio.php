<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI: ембединги (модуль App\Modules\AI)
    |--------------------------------------------------------------------------
    |
    | driver:     "laravel-ai" (реальний провайдер) або "fake" (детермінований, без мережі).
    |             Значення за замовчуванням навмисно реальне: якщо в проді забули
    |             змінну, краще впасти, ніж мовчки індексувати фейкові вектори.
    | provider:   ключ із config/ai.php → providers (ollama, voyageai, openai...).
    | model:      "bge-m3" локально; прод-модель мусить мати ТАКУ Ж розмірність.
    | dimensions: має збігатися з vector(N) у міграції listing_embeddings.
    |
    */

    'ai' => [
        'embeddings' => [
            'driver' => env('AI_EMBEDDING_DRIVER', 'laravel-ai'),
            'provider' => env('AI_EMBEDDING_PROVIDER', 'ollama'),
            'model' => env('AI_EMBEDDING_MODEL', 'bge-m3'),
            'dimensions' => 1024,
            'timeout' => (int) env('AI_EMBEDDING_TIMEOUT', 30),
        ],
    ],

];

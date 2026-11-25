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

        // Чат-модель для Structured Output (парсинг пошукового запиту у фільтри).
        // Локально: Ollama з моделлю, що вміє JSON-схеми; у проді: Claude Haiku.
        'llm' => [
            'driver' => env('AI_LLM_DRIVER', 'laravel-ai'),
            'provider' => env('AI_LLM_PROVIDER', 'ollama'),
            'model' => env('AI_LLM_MODEL', 'qwen2.5:3b'),
            // Парсинг запиту стоїть на гарячому шляху пошуку: довго чекати не можна, є fallback.
            'timeout' => (int) env('AI_LLM_TIMEOUT', 10),
        ],
    ],

    'search' => [
        // Скільки кандидатів бере кожна гілка (вектор / FTS / trigram) перед злиттям RRF.
        'candidates' => 50,
        // Стала RRF: 60 — канонічне значення з оригінальної статті.
        'rrf_k' => 60,
        'per_page' => 20,
        'query_embedding_ttl' => 86400,
    ],

];

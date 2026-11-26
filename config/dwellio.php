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

        // Стрімінг тексту для генерації оголошень. Окремо від `llm`: у проді це інша модель
        // (Sonnet замість Haiku), а таймаут — не гарячий шлях, а фонова задача.
        // Ланцюжок таймаутів строго зростає (ADR-002): LLM 180 с < Job 240 с < воркер 270 с < stop_grace_period 300 с.
        'generation' => [
            'driver' => env('AI_GENERATION_DRIVER', 'laravel-ai'),
            'provider' => env('AI_GENERATION_PROVIDER', 'ollama'),
            'model' => env('AI_GENERATION_MODEL', 'qwen2.5:3b'),
            'timeout' => (int) env('AI_GENERATION_TIMEOUT', 180),
            'temperature' => 0.7,
        ],
    ],

    'generation' => [
        // Пауза між пакетами чанків у WebSocket: ~10 повідомлень/с на генерацію замість сотень.
        'flush_interval_ms' => 100,
        // Кирилиця в JSON екранується як \uXXXX (6 байт/символ); ліміт повідомлення Reverb — 10 000 байт.
        'max_chunk_chars' => 1000,
        // Після стількох поспіль невдалих broadcast стрім у браузер вимикається (генерація триває).
        'max_broadcast_failures' => 3,
        // Запис queued/processing, старший за цей час, вважається завислим і не блокує новий запит.
        // = retryUntil() Job-а: довше Job легально жити не може.
        'stale_after_seconds' => 900,
        'rate_limit' => [
            'per_minute_per_ip' => (int) env('GENERATION_RATE_PER_MINUTE', 5),
            'per_day_global' => (int) env('GENERATION_RATE_PER_DAY', 200),
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

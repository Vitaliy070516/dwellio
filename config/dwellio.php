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
            // Витягування фактів, а не творчість: 0 = відтворюваний розбір (і чесні цифри search:eval).
            'temperature' => 0.0,
            // JSON фільтрів ≈ 100 токенів; стеля відсікає «розговорливу» модель (FinOps).
            'max_tokens' => 500,
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

        /*
        | Денна стеля токенів (input + output) на кожну ціль, лічильники в Redis (ключі ai:tokens:*).
        | Скидається опівночі UTC. Вичерпано → провайдера не викликаємо: пошук деградує до SQL/FTS,
        | генерація відповідає 503 з Retry-After. Окремі бюджети = переіндексація не «з'їсть» генерацію.
        |
        | Поверх токенів — спільна стеля в доларах для ВСІХ платних викликів (ключі ai:cost:*):
        | денна скидається опівночі UTC, місячна — 1-го числа. Безкоштовні виклики (Ollama, Fake)
        | $-стелею не блокуються. 0 = аварійний вимикач: жодного платного виклику.
        */
        'budget' => [
            // null = сховище кешу за замовчуванням (CACHE_STORE=redis); у тестах — array.
            'store' => env('AI_BUDGET_CACHE_STORE'),
            'daily_tokens' => [
                'embeddings' => (int) env('AI_BUDGET_EMBEDDINGS_DAILY', 2_000_000),
                'query_parsing' => (int) env('AI_BUDGET_QUERY_PARSING_DAILY', 500_000),
                'generation' => (int) env('AI_BUDGET_GENERATION_DAILY', 1_000_000),
            ],
            'daily_usd' => (float) env('AI_BUDGET_USD_DAILY', 1.0),
            'monthly_usd' => (float) env('AI_BUDGET_USD_MONTHLY', 10.0),
        ],

        /*
        | Ціни в USD за 1M токенів (перевірено 2026-11-26: platform.claude.com, docs.voyageai.com/docs/pricing).
        | Ціна береться за ID моделі з AI_*_MODEL. Невідома платна модель → fallback (навмисно дорогий)
        | + warning у лог: забута ціна має швидше вичерпати стелю, а не рахуватися як $0.
        | Не враховано: знижки prompt caching / Batch API, безкоштовні 200M токенів Voyage,
        | вища ціна Haiku 5.5 для промптів > 100K токенів (наші промпти — одиниці тисяч).
        */
        'pricing' => [
            'free_providers' => ['ollama'],
            'models' => [
                'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
                'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
                'voyage-4-lite' => ['input' => 0.02, 'output' => 0.0],
                'voyage-4' => ['input' => 0.06, 'output' => 0.0],
                'voyage-4-large' => ['input' => 0.12, 'output' => 0.0],
            ],
            'fallback' => ['input' => 4.00, 'output' => 20.00],
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

    /*
    |--------------------------------------------------------------------------
    | DEMO_MODE (День 12): публічне демо через Cloudflare Tunnel
    |--------------------------------------------------------------------------
    |
    | Один перемикач замість набору правок .env (див. `make demo-up`):
    | - запис квартир (POST/PUT/DELETE /api/listings) → 403 `demo_read_only`; читання, пошук і генерація працюють;
    | - ліміти нижче діють ЯК СТЕЛЯ: береться менше з «звичайного» і демо-значення, тож демо
    |   ніколи не буває м'якшим за локалку, навіть якщо в .env звичайний ліміт занижено чи завищено;
    | - `app.debug` примусово false (стек-трейси і SQL назовні не витікають, навіть якщо забули .env).
    |
    */
    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
        'rate_limit' => [
            'search_per_minute_per_ip' => (int) env('DEMO_SEARCH_RATE_PER_MINUTE', 10),
            'generation_per_minute_per_ip' => (int) env('DEMO_GENERATION_RATE_PER_MINUTE', 2),
            // Без автентифікації ліміт «на день на IP» — єдине, що не дає одному гостю з'їсти денну стелю всіх.
            'generation_per_day_per_ip' => (int) env('DEMO_GENERATION_RATE_PER_DAY_PER_IP', 10),
            'generation_per_day_global' => (int) env('DEMO_GENERATION_RATE_PER_DAY', 50),
        ],
    ],

    /*
    | Куди браузер підключає WebSocket (Reverb). Віддається сторінці в runtime (meta-тег), а не
    | вшивається у JS під час `npm run build` (VITE_*): одна збірка працює і локально, і за тунелем
    | з випадковим доменом, і на AWS — «build once, deploy anywhere».
    | host порожній (або DEMO_MODE) → той самий origin, що й сторінка: wss://<домен>/app/… через Caddy.
    | VITE_REVERB_* — запасні імена з Дня 6, щоб старий .env працював без правок.
    */
    'reverb_client' => [
        'host' => env('REVERB_CLIENT_HOST', env('VITE_REVERB_HOST')),
        'port' => env('REVERB_CLIENT_PORT', env('VITE_REVERB_PORT')),
        'scheme' => env('REVERB_CLIENT_SCHEME', env('VITE_REVERB_SCHEME')),
    ],

    'search' => [
        // Скільки кандидатів бере кожна гілка (вектор / FTS / trigram) перед злиттям RRF.
        'candidates' => 50,
        // Стала RRF: 60 — канонічне значення з оригінальної статті.
        'rrf_k' => 60,
        'per_page' => 20,
        'query_embedding_ttl' => 86400,
        // Денні лічильники hit/miss кешів розбору й ембедингу запиту (`make search-stats`).
        'cache_stats' => true,
        // Еталонні запити для `search:eval` (Recall@10, MRR): підбір rrf_k і candidates цифрами.
        'golden_set' => resource_path('eval/search-golden-set.json'),
        // Кожен некешований запит = виклик LLM + ембединг. Ліміт на IP захищає бюджет від одного клієнта.
        'rate_limit' => [
            'per_minute_per_ip' => (int) env('SEARCH_RATE_PER_MINUTE', 30),
        ],
    ],

];

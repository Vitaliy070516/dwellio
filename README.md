# Dwellio — AI-помічник для оренди житла в Києві

[![CI](https://github.com/Vitaliy070516/dwellio/actions/workflows/ci.yml/badge.svg)](https://github.com/Vitaliy070516/dwellio/actions/workflows/ci.yml)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20)
![PHPStan max](https://img.shields.io/badge/PHPStan-max-brightgreen)

Пет-проєкт рівня Senior Backend: модульний моноліт на Laravel 13 з двома AI-сценаріями.

1. **Семантичний пошук квартир** за запитом природною мовою — *«тихо, біля метро, можна з котом, до 20 тисяч»*. LLM розкладає запит на жорсткі SQL-фільтри + семантичний залишок, далі hybrid search: `pgvector` (HNSW) + повнотекстовий пошук + `pg_trgm`, злиті через Reciprocal Rank Fusion.
2. **Генерація оголошень для OLX і rieltor.ua** у фоновому воркері зі стрімінгом тексту в браузер через WebSockets (Laravel Reverb).

Головна ідея дизайну: **AI — ненадійна і платна залежність**. Тому кожен виклик LLM має fallback, ретраї з межею, DLQ і бюджет у Redis — у токенах на кожну ціль і в доларах на весь сервіс. Пошук працює навіть тоді, коли AI повністю лежить.

> Статус: **MVP готовий** (тиждень 2 з 6): пошук, генерація зі стрімінгом, OpenAPI, ліміти витрат у $, публічне демо через тунель. Зараз — AWS + observability (тиждень 3), Kubernetes (тиждень 4). План — [`docs/roadmap.md`](docs/roadmap.md), архітектура і журнал рішень по днях — [`docs/architecture.md`](docs/architecture.md).

## Як це виглядає

| Пошук природною мовою | Генерація оголошення (стрім через WebSocket) |
|---|---|
| [![Пошук: AI розібрав запит на фільтри](docs/screenshots/search.png)](docs/screenshots/search.png) | [![Картка квартири з готовим оголошенням для OLX](docs/screenshots/ad-generation.png)](docs/screenshots/ad-generation.png) |
| LLM розклав *«тихо біля метро, можна з котом, до 20 тисяч»* на фільтри (`≤ 20 000 грн`, `метро ≤ 500 м`, `з тваринами`) і семантичний залишок *«тихо, з котом»*. Бейджі під карткою — якими гілками RRF знайдено квартиру. | Текст іде частинами з воркера через Reverb; після завершення — знімок із БД. На скріншоті — локальна `qwen2.5:3b` (3B параметрів, $0), у проді — Claude. |
| **Деградація замість помилки** | **OpenAPI / Swagger UI** |
| [![Пошук без LLM: жовтий банер і пошук за всім текстом](docs/screenshots/search-degraded.png)](docs/screenshots/search-degraded.png) | [![Swagger UI зі специфікацією Dwellio API](docs/screenshots/api-docs.png)](docs/screenshots/api-docs.png) |
| Модель не відповіла за 10 с (холодний старт Ollama) → пошук не падає: банер пояснює причину (`meta.degraded`), запит шукається за всім текстом через вектор + FTS + trigram. | `http://localhost/docs/api`: специфікація генерується з коду (Scramble), помилки — Problem Details (RFC 9457) з машиночитним `code`; CI падає, якщо закомічений `openapi.json` розійшовся з кодом. |

## Архітектура

```mermaid
flowchart LR
    Browser["Браузер<br/>(Echo)"]

    subgraph App["Laravel 13 — модульний моноліт (один Docker-образ)"]
        API["php-fpm<br/>REST API"]
        Reverb["Reverb<br/>WebSockets"]
        WE["worker:<br/>embeddings"]
        WG["worker:<br/>generation"]
    end

    PG[("PostgreSQL 17<br/>+ pgvector")]
    Redis[("Redis<br/>кеш · rate limit ·<br/>бюджет токенів · pub/sub")]
    MQ[["RabbitMQ<br/>+ DLQ"]]
    LLM["LLM / Embeddings<br/>Ollama (dev) ·<br/>Claude + Voyage (prod)"]

    Browser -- "HTTPS" --> API
    Browser <-- "WSS" --> Reverb
    API -- "SQL + vector" --> PG
    API -- "throttle, cache" --> Redis
    API -- "publish job" --> MQ
    MQ --> WE & WG
    API -- "query → filters" --> LLM
    WE & WG --> LLM
    WE -- "upsert vectors" --> PG
    WG -- "chunks / status" --> Reverb
    Reverb <--> Redis
```

### Генерація оголошення: послідовність

```mermaid
sequenceDiagram
    autonumber
    participant B as Браузер
    participant A as API (php-fpm)
    participant Q as RabbitMQ
    participant W as worker:generation
    participant L as LLM
    participant R as Reverb

    B->>A: POST /api/listings/{id}/ads
    A->>A: throttle (IP, день) + бюджет токенів
    A->>Q: GenerateListingAdJob
    A-->>B: 202 + одноразовий токен
    B->>R: subscribe private-listing-ads.{uuid}
    Q->>W: deliver
    W->>L: stream (max_tokens)
    loop кожні ~100 мс
        L-->>W: дельти тексту
        W->>R: ad.chunk (attempt, seq)
        R-->>B: ad.chunk
    end
    W->>W: валідація → запис у БД, облік токенів
    W->>R: ad.completed
    B->>A: GET /api/listing-ads/{uuid} (джерело істини)
```

### Модулі

| Модуль | Відповідальність | Залежить від |
|---|---|---|
| `Listings` | Квартири: CRUD, індексація ембедингів | — |
| `Search` | Розбір запиту (Structured Output), hybrid search, RRF | `Listings`, `AI\Contracts` |
| `Generation` | Оголошення OLX / rieltor.ua, стрімінг, канали Reverb | `Listings`, `AI\Contracts` |
| `AI` | Контракти над LLM, адаптери `laravel/ai`, Fake-и, бюджет токенів | — |

Межі перевіряються Pest Architecture tests ([`tests/Architecture`](tests/Architecture)): `Search` не імпортує `Generation`, SDK LLM дозволено лише в `App\Modules\AI\Services`.

## Архітектурні рішення (ADR)

| ADR | Рішення | Коротко чому |
|---|---|---|
| [001](docs/adr/001-modular-monolith.md) | Модульний моноліт | Один деплой на 1 ГБ RAM; межі — тестами; масштабуємо процеси |
| [002](docs/adr/002-rabbitmq-for-ai-jobs.md) | RabbitMQ для AI-задач | DLQ, ack, безпечні 3-хвилинні Job-и; ланцюжок таймаутів |
| [003](docs/adr/003-pgvector-hybrid-search.md) | pgvector + RRF | Вектор і `WHERE` в одному SQL, без синхронізації з окремим store |
| [004](docs/adr/004-ai-sdk-choice.md) | Laravel AI SDK за власним контрактом | Ембединги Ollama, Fake-и, usage; SDK замінюється одним адаптером |
| [005](docs/adr/005-reverb-streaming-from-worker.md) | Reverb + стрімінг із воркера | php-fpm не тримає стрім; БД — джерело істини |

## Стек

**Backend:** PHP 8.4, Laravel 13, Laravel AI SDK, Laravel Reverb · **Дані:** PostgreSQL 17 + pgvector, Redis, RabbitMQ · **AI:** Ollama (`bge-m3`, `qwen2.5:3b`) локально за $0, Anthropic Claude + Voyage AI у проді, Fake-и в тестах · **Якість:** Pest (Feature / Unit / Architecture), Vitest + jsdom (фронт), PHPStan `level: max`, Pint · **DevOps:** Docker / Sail, GitHub Actions + GitLab CI, Terraform (AWS), k3d (тиждень 4) · **Observability:** Prometheus, Loki, Grafana (профіль `obs`).

## Швидкий старт

Потрібні Docker (на Windows — Docker Desktop з WSL2 Integration) і `make`. PHP, Composer і Node локально не потрібні: усе виконується в контейнерах Sail.

```bash
git clone git@github.com:Vitaliy070516/dwellio.git && cd dwellio
```

```bash
cp .env.example .env
```

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

```bash
make up
```

```bash
./vendor/bin/sail artisan key:generate
```

```bash
./vendor/bin/sail artisan migrate --seed
```

```bash
make queue-setup
```

```bash
make build
```

За замовчуванням усі AI-драйвери — `fake` (детерміновані відповіді, без мережі й витрат). Щоб працювати з реальними моделями локально:

```bash
make up-ollama
```

і в `.env` виставити `AI_EMBEDDING_DRIVER=laravel-ai`, `AI_LLM_DRIVER=laravel-ai`, `AI_GENERATION_DRIVER=laravel-ai` (моделі `bge-m3` і `qwen2.5:3b` треба один раз завантажити в Ollama через `ollama pull`).

Фонові воркери окремими контейнерами:

```bash
make up-workers
```

| Що | Адреса |
|---|---|
| API | `http://localhost/api` |
| Демо: пошук і картка квартири з генерацією (потрібен `make build`) | `http://localhost/` |
| Swagger UI / специфікація | `http://localhost/docs/api` · [`docs/openapi.json`](docs/openapi.json) |
| Health check | `http://localhost/up` |
| RabbitMQ UI | `http://localhost:15672` |

### Публічне демо через Cloudflare Tunnel

```bash
make demo-up
```

Одна команда збирає фронт, кешує конфіг у режимі `DEMO_MODE` (лише читання квартир, жорсткіші ліміти, `APP_DEBUG=false`), проганяє чеклист `demo:check`, прогріває моделі Ollama і відкриває Quick Tunnel (`*.trycloudflare.com`, без акаунта, адреса нова при кожному старті). `.env` не змінюється.

**Демо-посилання:** постійного немає свідомо — Quick Tunnel дає нову адресу при кожному старті, а демо крутиться на ноутбуці з локальною Ollama (пошук ~8 с на CPU). Живе посилання — на запит, на час співбесіди (`make demo-url`). Постійна адреса з'явиться разом із деплоєм на AWS (тиждень 3).

Повернутися до локальної розробки:

```bash
make demo-down
```

### Приклади API

```bash
curl -s "http://localhost/api/search?q=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("тихо біля метро, можна з котом, до 20 тисяч"))')"
```

```bash
curl -s -X POST http://localhost/api/listings/1/ads -H 'Content-Type: application/json' -d '{"platform":"olx"}'
```

Відповідь пошуку містить `meta.mode` (`hybrid` / `text_only` / `filters_only`), `meta.parsed_by_llm` і `meta.degraded` (`{"query_parsing": "budget_exceeded", "embeddings": null}`) — клієнт бачить не лише режим деградації, а й причину.
Кожна квартира у видачі має `match`: які гілки її знайшли і на якій позиції (`{"matched_by": ["vector", "fts"], "ranks": {"vector": 7, "fts": 2}, "score": 0.047}`).

### Якість пошуку (офлайн-оцінка)

```bash
make search-eval c="--rrf-k=10,30,60 --candidates=20,50,100 --details"
```

На платних моделях (Anthropic / Voyage) команда вимагає `--force`: прогін витрачає спільний денний бюджет AI.

30 еталонних запитів (`resources/eval/search-golden-set.json`) → Recall@10 і MRR@10. На bge-m3 + qwen2.5:3b: **0.967 / 0.967** (базова лінія — 0.527 / 0.833). Головний виграш дали не параметри RRF, а боротьба з вигаданими фільтрами LLM: temperature 0, few-shot і перевірка, що кожен фільтр має опору в тексті запиту. Деталі — `docs/architecture.md`, День 9.

## Контроль витрат на AI (FinOps)

Головне правило: **рахунок обмежує застосунок, а не надія.** Розробка і тести коштують $0 (Ollama + Fake-и), а в проді кожен виклик LLM проходить через бюджет у Redis.

### Скільки коштує одна операція (прод: Claude Haiku 5.5 / Sonnet 5.5, Voyage `voyage-4`)

| Операція | Токени (виміряно, `make ai-usage` і лог генерацій) | Вартість | На $1 |
|---|---|---|---|
| Пошук, промах кешу: розбір запиту (Haiku) + ембединг запиту (Voyage) | ~1 000 in / ~70 out + ~10 | **~$0.00014** | ~7 000 пошуків |
| Пошук, повторний запит (кеш розбору і вектора в Redis) | 0 | **$0** | ∞ |
| Генерація оголошення, типова (Sonnet) | ~700 in / ~250 out | **~$0.004** | ~250 оголошень |
| Генерація, найгірший випадок: rieltor.ua, стеля `max_tokens` 1 200, 2 платні спроби | 2 × (700 in / 1 200 out) | **~$0.027** | ~37 оголошень |
| Переіндексація всіх 300 квартир (Voyage) | ~200 на квартиру | **< $0.01** | — |

Токени виміряно на локальних моделях (токенізатор qwen2.5); у Claude українська може давати більше токенів, тож цифри — порядок величини, а не рахунок. Ціни — у [`config/dwellio.php`](config/dwellio.php) (`dwellio.ai.pricing`, перевірено 2026-11-26). Висновок для дизайну: **пошук майже безкоштовний, гроші — у генерації**, тому і ліміти на генерацію жорсткіші, і модель для неї окрема.

### Лінії захисту

| Лінія | Локально | `DEMO_MODE` | Що обмежує |
|---|---|---|---|
| Пошук на IP | 30/хв | 10/хв | Одного клієнта (кожен промах кешу — виклик LLM) |
| Генерація на IP | 5/хв | 2/хв | Спам кнопкою |
| Генерація на IP на день | — | 10 | Одного гостя, що «з'їдає» денну стелю всіх |
| Генерація глобально на день | 200 | 50 | Кількість дорогих операцій |
| Авторизація WebSocket-каналу на IP | 30/хв | 30/хв | Перебір токенів / навантаження на БД |
| **Бюджет токенів** на кожну ціль на день | `embeddings` 2M · `query_parsing` 500K · `generation` 1M | те саме | Реальне споживання, незалежно від кількості запитів |
| **Стеля в $** на всі цілі разом | день $1 · місяць $10 | те саме | Гаманець: токени різних моделей коштують по-різному |

Денні ліміти генерацій рахують лише прийняту роботу (`202`): дешеві `404/409/422` не «спалюють» стелю (знахідка рев'ю Дня 13). Ліміти запитів — у Redis, тож спільні для всіх реплік.

Бюджет живе в декораторах над AI-контрактами (`Budgeted*Client`), тож жоден виклик не може обійти облік — ні в проді, ні в тестах. Окремі бюджети для `embeddings`, `query_parsing`, `generation`: переіндексація не «з'їсть» генерацію. Вартість рахується в мікродоларах цілим `INCRBY` (без похибок float). Локальні Ollama/Fake коштують $0 і $-стелею не блокуються (тож `AI_BUDGET_USD_DAILY=0` — аварійний вимикач лише платних викликів); невідома платна модель рахується за навмисно дорогою fallback-ціною + warning у лог.

**Що відбувається, коли грошей немає:** провайдера не викликаємо. Пошук деградує до SQL/FTS (банер у UI, причина в `meta.degraded`), генерація відповідає `503 ai_budget_exhausted` з `Retry-After` до півночі UTC або до 1-го числа, Job-и йдуть у DLQ без ретраїв.

```bash
make ai-usage
```

```bash
make search-stats
```

`ai-usage` — токени і $ за сьогодні проти стель; `search-stats` — hit rate кешів пошуку (нормалізований ключ: «Тихо, з котом!» = «тихо з котом»).

**Чесні межі:** бюджет м'який — паралельні виклики можуть перевищити ліміт на (воркери × `max_tokens`); $-стеля — запобіжник застосунку, а не білінгу, тому в проді ліміти треба виставити й у консолях Anthropic / Voyage та AWS Budgets — див. [ранбук AWS](docs/runbooks/aws-account-setup.md), крок 4.

## Якість і CI

```bash
make check
```

`make check` = Pint → PHPStan (`max`) → OpenAPI → Pest → JS-тести (Vitest), ті самі стадії в [`.github/workflows/ci.yml`](.github/workflows/ci.yml) і [`.gitlab-ci.yml`](.gitlab-ci.yml) (GitLab Free отримує коміти push-дзеркалом із GitHub Actions — pull-mirroring на Free недоступний). Окремий job перевіряє Terraform (`fmt` + `validate` без AWS-акаунта).

Тести покривають неочевидні збої: падіння LLM посеред стріму, невалідний JSON від моделі, обрив з'єднання без фінальної події, дублікати повідомлень у черзі, недоступність RabbitMQ і Reverb, вичерпаний бюджет токенів.

## Інфраструктура

- **Terraform** ([`terraform/`](terraform)): VPC з однією публічною підмережею без NAT, Security Group лише 80/443 (доступ адміністратора — SSM Session Manager, без SSH), EC2 `t3.small` з IMDSv2 і зашифрованим gp3 ([ADR-006](docs/adr/006-ec2-instance-size.md): RAM процесів виміряно, 1 ГБ не вистачає), Elastic IP, приватний S3-бакет для `pg_dump` (versioning, SSE, lifecycle 30 днів, TLS-only), IAM-роль інстансу з правом лише **писати** бекапи. Remote state — S3 з нативним lock-файлом.
- **Bootstrap акаунта** ([`terraform/bootstrap`](terraform/bootstrap)) — окремий стек, який переживає `destroy` проду: state-бакет (`prevent_destroy`), два бюджети (net-витрати $1 і швидкість витрати кредитів — бюджет за замовчуванням рахує після кредитів і на Free Plan мовчить), IAM-політика Terraform з least privilege і **permissions boundary** (право `CreateRole` без стелі = ескалація до адміна). Доступ — лише IAM Identity Center + MFA, жодних access keys.

```bash
make tf-validate
```

- **AWS** — з тижня 3, за [ранбуком безпечного старту](docs/runbooks/aws-account-setup.md): root лише з MFA і для білінгу, SSO-профілі `dwellio-admin` (bootstrap) і `dwellio-tf` (щодня), ліміти в консолях Anthropic / Voyage, `terraform destroy` після демо.
- **Kubernetes** — тиждень 4: Kustomize overlays, HPA, k3d.

## System Design & SRE

> Повна версія (SLO/SLI, бекапи з перевіреним restore, 100×) — тиждень 6. Поточні тези:

- **Деградація замість відмови:** LLM недоступна → пошук без LLM; немає ембедингів → FTS + trigram; Reverb недоступний → результат через `GET`.
- **SLI з логів:** кожна спроба генерації пише один JSON-рядок (`duration_ms`, `time_to_first_token_ms`, `outcome`, токени) — з нього в Loki будуються p95 TTFT і частка невдалих генерацій.
- **Вузькі місця при 100×:** php-fpm і воркери масштабуються горизонтально (HPA/KEDA за довжиною черги); Reverb — через Redis pub/sub; PostgreSQL — read replica для пошуку, далі окремий vector store; ліміти провайдера LLM — черга як буфер + бюджет токенів.
- **Відомі обмеження:** exactly-once для генерації немає (падіння воркера після відповіді LLM = повторна оплата); бюджет токенів м'який — паралельні виклики можуть перевищити ліміт на (воркери × `max_tokens`).

## Структура

```text
app/Modules/{Listings,Search,Generation,AI}/   # Actions, DTO, Http, Jobs, Models, Contracts, Services
app/Shared/                                    # спільне без залежностей від модулів
tests/{Feature,Unit,Architecture}/
docker/  compose.yaml                          # Sail; profiles: ollama, obs, workers
terraform/{bootstrap,modules,envs/prod}/
docs/{architecture.md,roadmap.md,adr/,runbooks/}
```

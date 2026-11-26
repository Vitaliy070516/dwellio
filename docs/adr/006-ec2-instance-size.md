# ADR-006: `t3.small` проти `t3.micro` + swap для prod-light

- **Статус:** Прийнято (2026-11-26)
- **Контекст дня:** День 15, тиждень 3
- **Пов'язано:** `terraform/modules/ec2`, `terraform/bootstrap/iam.tf` (`allowed_instance_types`), ADR-001 (моноліт), ADR-002 (RabbitMQ), ADR-005 (Reverb), День 16 (`compose.prod.yaml`), День 19 (observability)

## Контекст

Prod-light — один EC2, на якому в Docker Compose працює весь застосунок: Caddy, php-fpm, Reverb, два воркери черг, PostgreSQL + pgvector, Redis, RabbitMQ. Ембединги й генерацію в проді робить API (Voyage / Anthropic), тому Ollama на сервер не їде, і CPU потрібно мало.

Гроші: акаунт на AWS Free Plan, кредити $100 + до $100 за завдання, 6 місяців. Інстанс живе лише тоді, коли з ним працюємо (`apply` → демо → `destroy`). На Free Plan дозволені лише free-tier-eligible типи: `t3.micro`, `t3.small`, `t4g.micro`, `t4g.small`, `c7i-flex.large`, `m7i-flex.large`.

Питання ADR: чи влізе все в 1 ГБ RAM зі swap, і що з observability (Prometheus, Loki, Grafana) може жити на сервері.

## Виміряна пам'ять (локально, 2026-11-26)

`docker stats` і `ps -o rss` на dev-образах Sail у простої після кількох годин роботи; БД — 275 квартир із сідера.

| Процес | Виміряно (dev, простій) | Прогноз піку в проді | `mem_limit` для `compose.prod.yaml` |
|---|---|---|---|
| ОС (AL2023) + `dockerd` + `containerd` + SSM agent | — | ≈ 250 МБ | — |
| Caddy (TLS, проксі WebSocket) | — | 30–40 МБ | 64 МБ |
| PHP-веб: `artisan serve`, 5 процесів | 258 МБ (54–84 МБ RSS на процес) | php-fpm, 4 дочірні × ≈ 45 МБ + OPcache ≈ 250 МБ | 320 МБ |
| Reverb | 58 МБ | ≈ 80 МБ на 100 з'єднаннях | 128 МБ |
| Воркер `embeddings` | 52 МБ | ≈ 80 МБ | 128 МБ |
| Воркер `generation` (стрім + буфер чанків) | 52 МБ | ≈ 100 МБ | 160 МБ |
| PostgreSQL 17 (`shared_buffers` 128 МБ, БД 14 МБ, HNSW 2.2 МБ) | 74 МБ | ≈ 250 МБ (буфери + 10–15 бекендів) | 320 МБ |
| Redis | 13 МБ | ≈ 20 МБ | 64 МБ |
| RabbitMQ (Erlang VM) | 89 МБ | ≈ 150 МБ | 256 МБ |
| **Разом застосунок** | **≈ 600 МБ** | **≈ 1.2 ГБ разом з ОС** | 1.44 ГБ (сума стель) |
| Observability: Prometheus (3 доби) + Loki + Grafana + exporters | — | ≈ 350 МБ | 384 МБ |

Що з цього видно:

- **`t3.micro` (1 ГБ, ≈ 900 МБ доступно після ядра):** самому застосунку бракує ≈ 300 МБ навіть без observability. Swap закриє дірку, але в swap підуть «холодні» сторінки PostgreSQL і php-fpm, і p95 пошуку стрибне з десятків мілісекунд до секунд. Swap на EBS gp3 — це ще й мережевий диск з лімітом IOPS.
- **`t3.small` (2 ГБ):** застосунок ≈ 1.2 ГБ, лишається ≈ 700 МБ: observability (≈ 350 МБ) і page cache для PostgreSQL. Swap 1 ГБ — страховка від OOM-killer на рідкісному піку (наприклад, `listings:reindex`), а не робоча пам'ять.
- Числа dev-образів завищені (Xdebug, dev-залежності), прод-образ Дня 16 буде легшим. Але при 1 ГБ і запасу −300 МБ це рішення не змінює.

## Порівняння

Ціни — on-demand Linux, `eu-central-1`, станом на листопад 2026 (сторонні агрегатори, звірити з AWS Pricing Calculator перед apply). До інстансу додаються gp3 20 ГБ (≈ $1.9/міс) і публічна IPv4 / Elastic IP ($0.005/год ≈ $3.65/міс, нараховується й тоді, коли інстанс зупинено).

| Критерій | `t3.micro` + 2 ГБ swap | **`t3.small` + 1 ГБ swap** | `c7i-flex.large` (4 ГБ) |
|---|---|---|---|
| RAM / vCPU | 1 ГБ / 2 | 2 ГБ / 2 | 4 ГБ / 2 |
| Ціна інстансу | $0.012/год | $0.024/год | ≈ $0.10/год |
| 24/7 разом із диском і IPv4 | ≈ $14/міс | ≈ $23/міс | ≈ $80/міс |
| 6 місяців 24/7 із кредитів $200 | $86 (43%) | $138 (69%) | не влізе |
| Режим «4 год/день» + `destroy` після роботи (6 міс, разом із диском і IPv4) | ≈ $14 | ≈ $23 | ≈ $79 |
| Влізе застосунок | Лише зі swap, з деградацією latency | Так, із запасом ≈ 700 МБ | Так |
| Observability на сервері | Ні | Полегшена (3 доби метрик) | Повна |
| Базова продуктивність CPU (credits standard) | 10% від 2 vCPU | 20% від 2 vCPU | без кредитної моделі |

## Рішення

**`t3.small` + swap 1 ГБ, CPU credits `standard`.**

- Різниця з `t3.micro` — $0.012/год. За реальний режим (≈ 4 год/день) це ≈ $9 за 6 місяців, а `t3.micro` робить поведінку проду непередбачуваною саме там, де це видно на демо: латентність пошуку і стрім генерації.
- `t3.micro` лишається дозволеним (validation у модулі `ec2` і IAM-запобіжник `allowed_instance_types`) для коротких перевірок Terraform: `apply` → smoke → `destroy`.
- `credit_specification = standard`: якщо CPU-кредити закінчаться, інстанс сповільниться до базових 20%, але не нарахує плату за CPU (`unlimited` нараховує тихо).
- `instance_type` за замовчуванням змінено на `t3.small` у `envs/prod` і в модулі `ec2`; swap — 1 ГБ замість 2.

**Observability на сервері — лише «живе» і коротке:**

| Де | Що | Чому |
|---|---|---|
| Прод (`t3.small`) | Prometheus з retention 72 год і scrape раз на 30 с, Loki з retention 72 год на локальному диску, Grafana з дашбордами з provisioning, `postgres_exporter`, `redis_exporter`, вбудований Prometheus-плагін RabbitMQ. Сумарна стеля ≈ 384 МБ | Алерти «$ за день > 80% стелі» і «DLQ не порожня» мають працювати там, де живуть гроші й черги |
| Лише локально (профіль `obs`) | Довга історія, експерименти з дашбордами, профілювання, Ollama | Пам'ять і диск, а після `destroy` історія метрик однаково зникає |

Параметри, які з цього випливають для Дня 16 (`compose.prod.yaml`): php-fpm `pm = static`, `pm.max_children = 4`; PostgreSQL `shared_buffers = 128MB`, `max_connections = 30`; RabbitMQ `vm_memory_high_watermark.absolute = 200MiB`; Redis `maxmemory 48mb` з **`noeviction`**. Чому не `allkeys-lru`: у Redis лежать лічильники бюджету в $ і rate limit. Якщо їх витіснить LRU, ліміт тихо обнулиться. Нехай краще запис упаде з помилкою, ніж бюджет перестане працювати.

## Наслідки

**Плюси**
- Застосунок поміщається в RAM без swap, тож латентність на демо така сама, як локально.
- Алерти й дашборд працюють на тому самому сервері: SRE-частину портфоліо видно на живому прикладі.
- Навіть 24/7 протягом 6 місяців витрачає лише 69% кредитів. У режимі «працюємо → `destroy`» запасу ще більше.

**Мінуси і ризики**
- Удвічі дорожча година, ніж у `t3.micro`. Якщо забути інстанс увімкненим, це ≈ $23/міс замість ≈ $14. Від цього страхує бюджет `dwellio-credit-burn` (прогноз > 100% → лист) у `terraform/bootstrap`.
- Усе на одній машині: падіння інстансу = падіння всього (SPOF). Для prod-light прийнято свідомо, вихід — тиждень 4 (Kubernetes) і розділ «100× навантаження» в README.
- Прогноз пам'яті для проду поки розрахунковий. Його треба звірити з `docker stats` на EC2 на Дні 16.
- Elastic IP коштує гроші й на зупиненому інстансі. Тому після демо — `destroy`, а не `stop`.

## Альтернативи, що відхилені

- **`t4g.small` (ARM, Graviton):** приблизно на 20% дешевший за `t3.small` при тих самих 2 ГБ. Відхилено зараз, бо потрібні multi-arch образи (`docker buildx` для `linux/arm64` у CI) і інший AMI-параметр. Варто повернутися, коли CD стабілізується (див. умови перегляду).
- **`c7i-flex.large` / `m7i-flex.large`:** формально free-tier-eligible, але ≈ $0.10/год, тобто більша частина кредитів піде за 2–3 місяці 24/7. Зайва пам'ять для MVP.
- **Grafana Cloud Free замість self-hosted observability:** ≈ 60 МБ на агента (Alloy) замість 350 МБ, і історія переживає `destroy`. Відхилено: мета портфоліо — показати, що я вмію підняти й налаштувати Prometheus / Loki / Grafana сам. Це перший кандидат, якщо пам'яті забракне.
- **Керовані RDS / ElastiCache / Amazon MQ:** окремий рахунок за кожен сервіс (кілька $20+/міс), коли сам застосунок коштує $23. Для prod-light це надлишково.

## Умови перегляду

- `docker stats` на EC2 показує стабільно > 80% RAM або swap-in під навантаженням → спочатку Grafana Cloud замість локального стеку, потім `t3.medium` (вже не free-tier-eligible, потрібен Paid plan).
- CPU-кредити регулярно падають до нуля (метрика `CPUCreditBalance`) → дивитися, хто їсть CPU, і лише потім міняти тип.
- CD з multi-arch збіркою готовий → порівняти `t4g.small` за ціною й latency.

## Джерела

- Free-tier-eligible типи EC2: https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ec2-free-tier-usage.html
- AWS Free Plan і кредити: https://aws.amazon.com/blogs/aws/aws-free-tier-update-new-customers-can-get-started-and-explore-aws-with-up-to-200-in-credits/
- Ціни `t3.micro` / `t3.small` у `eu-central-1` (агрегатор): https://www.doit.com/compute/compute/aws/eu-central-1/t3.micro, https://www.devzero.io/instances/aws/regions/eu-central-1
- T3 CPU credits (`standard` / `unlimited`): https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/burstable-performance-instances.html

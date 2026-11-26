# ADR-002: RabbitMQ для AI-задач

- **Статус:** Прийнято (2026-11-26, рішення діє з Дня 4)
- **Контекст дня:** День 7, тиждень 1
- **Пов'язано:** `config/queue.php`, `app/Shared/Console/SetupRabbitMqTopology.php`, `GenerateListingAdJob`, `IndexListingEmbedding`, ADR-005

## Контекст

Дві фонові задачі ходять у платні зовнішні API: індексація ембедингів (секунди) і генерація оголошень зі стрімінгом (до 3 хвилин). Вимоги:

1. Повтор із backoff при тимчасовому збої, **без** нескінченних ретраїв (кожна спроба коштує токенів).
2. Dead Letter Queue: впалі задачі не зникають, їх видно й можна переграти.
3. Окремі черги й воркери: довга генерація не блокує швидку індексацію.
4. Ack лише після успіху: падіння воркера = повторна доставка, а не втрата.

## Порівняння

| Критерій | RabbitMQ | Redis (Laravel `redis` driver) | Amazon SQS |
|---|---|---|---|
| DLQ | Нативно: `x-dead-letter-exchange` → `*.failed` | Немає; лише таблиця `failed_jobs` | Нативно (redrive policy) |
| Ack / redelivery | AMQP ack, `consumer_timeout` | Емуляція через `retry_after` (повторна видача, якщо Job довший!) | Visibility timeout |
| Довгі Job-и (стрім 3 хв) | Безпечно, поки Job < `consumer_timeout` | `retry_after` мусить бути > найдовшого Job, інакше дубль | Visibility timeout до 12 год |
| Routing, пріоритети | Exchanges, bindings | Ні | Ні |
| Ops-ціна | +1 контейнер (~150 МБ RAM) | Redis уже є | $0 на Free Tier, але лише в AWS (локально — LocalStack) |
| Інтерв'ю-цінність | Висока: AMQP, DLX, prefetch | Низька | Середня |

## Рішення

RabbitMQ (`vladimir-yuldashev/laravel-queue-rabbitmq`), черги `embeddings` і `generation`, кожна має DLQ `{queue}.failed` через failed-exchange. Топологію створює ідемпотентна команда `dwellio:rabbitmq-setup`. Воркери — `queue:work rabbitmq` окремими контейнерами (профіль `workers`).

Політика повторів закладена в самі Job-и:

| Job | Повтори | Backoff | Стеля | Не ретраїмо |
|---|---|---|---|---|
| `IndexListingEmbedding` | `maxExceptions = 3` | 5 / 30 / 120 с | `retryUntil` 15 хв | невалідний вектор, вичерпаний бюджет токенів |
| `GenerateListingAdJob` | `maxExceptions = 2` | 10 / 60 с | `retryUntil` 15 хв, `failOnTimeout` | вичерпаний бюджет токенів, timeout |

`maxExceptions`, а не `tries`: «відкладення» від `WithoutOverlapping` не повинні з'їдати спроби. Ланцюжок таймаутів строго зростає: LLM 180 с < Job 240 с < `queue:work --timeout` 270 с < `stop_grace_period` 300 с — інакше деплой обриває стрім, RabbitMQ доставляє Job повторно, і ми платимо за токени двічі.

## Наслідки

**Плюси**
- DLQ і redelivery без власного коду; впалі задачі видно в RabbitMQ UI.
- Ізоляція черг: зависла генерація не гальмує індексацію.

**Мінуси і ризики**
- Ще один stateful-сервіс у prod-light (RAM, бекапи не потрібні — черги ефемерні).
- **Exactly-once немає.** Падіння воркера між відповіддю LLM і записом у БД = повторна оплата. Пом'якшення: ідемпотентність за хешем тексту (ембединги), атомарний claim за `attempt` (генерація), денний бюджет токенів (День 7).
- AMQP heartbeat вимкнено: PHP-воркер синхронно читає стрім і не встигає відповідати; захищає `consumer_timeout` (30 хв) > життя Job-а (15 хв).
- Лічильник бюджету — м'який: паралельні Job-и можуть перевищити денний ліміт на (кількість воркерів × `max_tokens`). Жорсткий варіант — резервувати `max_tokens` до виклику й повертати залишок після.

## Альтернативи, що відхилені

- **Redis-черги.** Простіші й для поточного обсягу достатні; відхилено через відсутність DLQ та небезпечну семантику `retry_after` для 3-хвилинних стрімів.
- **SQS.** Найкращий варіант «у хмарі», але прив'язує локальну розробку до AWS/LocalStack на тижнях 1–2, коли акаунта ще немає.

## Умови перегляду

- При 100×: HPA/KEDA за довжиною черги (тиждень 4). Якщо RabbitMQ на одному вузлі стане точкою відмови — quorum queues на 3 вузлах або перехід на SQS разом із міграцією в AWS.
- Якщо генерація стане синхронною (SSE без воркера) — черга `generation` не потрібна.

## Джерела

- [RabbitMQ: Dead Letter Exchanges](https://www.rabbitmq.com/docs/dlx), [Consumer acknowledgements](https://www.rabbitmq.com/docs/confirms), [consumer_timeout](https://www.rabbitmq.com/docs/consumers#acknowledgement-timeout)
- [Laravel 13: Queues — job expiration, timeouts, maxExceptions](https://laravel.com/docs/13.x/queues)

# ADR-004: Laravel AI SDK замість Prism для AI-шару

- **Статус:** Прийнято (2026-11-23)
- **Контекст дня:** День 4, тиждень 1
- **Пов'язано:** `docs/architecture.md` (розділи 2 і 4), ADR-001 (модульний моноліт)

## Контекст

Dwellio потребує від AI-шару чотирьох речей:

1. **Ембединги** для `pgvector` (`vector(1024)`): локально через Ollama (`bge-m3`), у проді через Voyage AI або OpenAI.
2. **Structured Output** для розбору запиту природною мовою у фільтри (День 5).
3. **Стрімінг тексту** Anthropic Claude для генерації оголошень (День 6).
4. **Нуль витрат у розробці й тестах:** Ollama локально, Fake у Pest, без реальних викликів.

Кандидати: **Prism** (`prism-php/prism`, спільнотний) і **Laravel AI SDK** (`laravel/ai`, першопартійний, у документації Laravel 13).

## Порівняння за критеріями проєкту

| Критерій | Laravel AI SDK | Prism |
|---|---|---|
| Ембединги: Ollama | Так, у таблиці Provider Support | У документації Prism для Ollama ембедингів немає (сторінка провайдера описує текст, стрімінг, structured) |
| Ембединги: Voyage AI | Так (`VoyageAI`, ключ `VOYAGEAI_API_KEY`) | Так, з опціями `inputType` (`query`/`document`) і `truncation` |
| Текст: Anthropic | Так | Так |
| Structured Output | Агент із `HasStructuredOutput` і `schema()`; для Anthropic є нативний `output_config.format` | `Prism::structured()` зі схемами; для Ollama немає нативного JSON-режиму, інструкції дописуються в промпт |
| Розмірність ембедингів | `->dimensions(N)` і ключ у `config/ai.php` | У документації не згадується |
| Fake для тестів | `Embeddings::fake()` з авто-вектором потрібної розмірності, `preventStrayEmbeddings()`, `assertGenerated()`; `Agent::fake()` | `Prism::fake()` + `EmbeddingsResponseFake`, вектор задається вручну |
| Облік токенів | `$response->usage` у кожній відповіді (`inputTokens`, `outputTokens`, `totalTokens()`) | `$response->usage->tokens` для ембедингів |
| Кеш ембедингів (FinOps) | Вбудований: `config/ai.php` → `caching.embeddings`, також `->cache(seconds: …)` | Немає, пишемо самі |
| Підтримка | Команда Laravel; версія оновлюється разом з фреймворком | Спільнота; у документації приклад `^0.3.0` |

## Рішення

Беремо **Laravel AI SDK** (`laravel/ai`), але **ховаємо його за власним контрактом** у модулі `AI`:

```text
App\Modules\AI\Contracts\EmbeddingClient   ← єдина точка входу для решти модулів
App\Modules\AI\Services\...                ← адаптери над laravel/ai (Ollama / Voyage / OpenAI)
App\Modules\AI\Testing\FakeEmbeddingClient ← детермінований Fake для Pest
```

Решта модулів (`Listings`, `Search`, `Generation`) залежать лише від контракту. Це вже примусово перевіряє Architecture-тест: `Prism`, `Laravel\Ai`, `Anthropic`, `OpenAI` дозволені тільки в `App\Modules\AI`.

### Чому саме так

- **Ембединги в Ollama задокументовані й протестовані в SDK**, а в Prism ні. Це основний локальний сценарій на 16 ГБ RAM, і він мусить працювати без обхідних шляхів.
- **Fake розв'язує проблему розмірності.** `Embeddings::fake()` повертає вектор потрібної довжини, тож тести не вимагають 1024 чисел вручну, а `preventStrayEmbeddings()` не дає тесту випадково вийти в мережу.
- **FinOps «з коробки».** Вбудований кеш ембедингів знадобиться на Дні 5: той самий запит користувача не повинен щоразу платити за токени.
- **Один пакет для трьох задач:** ембединги (Voyage / Ollama), structured output і стрімінг Claude.
- **Власний контракт знижує ризик.** `laravel/ai` має нумерацію 0.x, і між мінорними версіями змінювалась поведінка (наприклад, Anthropic structured output перейшов на нативний API у 0.9). Якщо SDK зламає API чи зникне, міняємо один адаптер, а не код доменних модулів.

## Наслідки

**Плюси**
- Менше власного коду: кеш, usage і Fake вже є.
- Підтримка від команди Laravel, документація в одному місці з фреймворком.
- Межі модулів лишаються чистими завдяки контракту.

**Мінуси і ризики**
- **Пакет до 1.0:** фіксуємо точну версію в `composer.lock`, оновлюємо свідомо й читаємо upgrade guide.
- **Вендорна прив'язка до абстракції SDK** (`Lab`, `Agent`). Пом'якшено контрактом `EmbeddingClient`.
- **Менше гнучкості в agent-циклах і tool calling**, ніж у Prism. Для нашого сценарію (один виклик ембедингу, один structured output, один стрім) це неважливо.
- **Не перевірено на практиці:** розмірність ембедингів Ollama (`bge-m3` → 1024) і поведінка `dimensions()` для Voyage. Підтверджуємо тестовим викликом на кроці 3 Дня 4 до того, як писати Job.

## Альтернативи, що відхилені

- **Prism.** Сильніший у tool calling та agent-циклах, але ембединги Ollama в документації відсутні, а Fake для ембедингів вимагає ручних векторів. Це поганий збіг із нашим локальним сценарієм.
- **Прямі HTTP-виклики** (Guzzle) до Ollama, Voyage і Anthropic. Найменше залежностей, але доведеться самим писати ретраї, облік токенів, стрімінг і Fake. Це вартісніше, ніж ризик 0.x-пакета.

## Умови, за яких перегляд рішення

- `laravel/ai` ламає API між мінорними версіями частіше, ніж раз на квартал.
- Нам знадобиться складний agent-цикл із багатьма інструментами (на Дні 6 генерація оголошень цього не вимагає).
- Voyage або Ollama зникають із підтримуваних провайдерів ембедингів.

## Джерела

- [Laravel AI SDK, документація 13.x](https://laravel.com/docs/13.x/ai-sdk): таблиця Provider Support, embeddings, structured output, testing, usage.
- [Prism: embeddings](https://prismphp.com/core-concepts/embeddings.html), [testing](https://prismphp.com/core-concepts/testing.html), [Ollama](https://prismphp.com/providers/ollama.html), [Voyage AI](https://prismphp.com/providers/voyageai.html).

> Частину розділів документації `laravel/ai` (середина великої сторінки) отримано у вигляді стислого переказу, не дослівно. Мінімальні версії PHP/Laravel і статус стабільності пакета на сторінці не вказані: **звіряємо під час `composer require laravel/ai`** і за потреби уточнюємо цей ADR.

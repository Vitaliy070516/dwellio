/**
 * Генерація оголошення в картці квартири.
 *
 * WebSocket — лише швидкий канал без гарантій доставки, джерело істини — GET /api/listing-ads/{id}:
 *  - після підписки й після кожного перепідключення беремо знімок через GET;
 *  - поки WebSocket не підключений — часте опитування GET (дочитування), коли підключений — рідке (страховка
 *    від втраченої події completed/failed);
 *  - пропуск у `seq` → перестаємо дописувати текст і чекаємо фінального результату;
 *  - нова спроба (`attempt`) → очищаємо текст попередньої.
 * Стан з'єднання видно завжди (бейдж), бо «тихий» обрив виглядає як зависання генерації.
 * Весь текст виводиться лише через textContent: відповідь LLM — недовірені дані.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const POLL_CONNECTED_MS = 10_000;
const POLL_DISCONNECTED_MS = 3_000;

const FAILURE_TEXT = {
    provider_unavailable: 'AI-провайдер недоступний.',
    invalid_output: 'Модель відповіла у неправильному форматі.',
    timeout: 'Генерація тривала задовго.',
    listing_unavailable: 'Квартира більше недоступна.',
    queue_unavailable: 'Черга недоступна.',
    budget_exceeded: 'Бюджет AI вичерпано, поки генерація чекала в черзі.',
    stale: 'Генерація зависла (воркер не відповідає).',
    unknown: 'Невідома помилка.',
};

const WS_STATES = {
    idle: ['WebSocket: не потрібен', 'bg-stone-100 text-stone-600'],
    connecting: ['WebSocket: підключення…', 'bg-stone-100 text-stone-600'],
    connected: ['WebSocket: наживо', 'bg-emerald-50 text-emerald-800'],
    lost: ['WebSocket: з\'єднання втрачено · дочитую через GET', 'bg-amber-50 text-amber-900'],
    unavailable: ['WebSocket недоступний · оновлення через GET', 'bg-amber-50 text-amber-900'],
};

const NOTICE_STYLES = {
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    error: 'border-red-300 bg-red-50 text-red-900',
};

function hoursUntil(seconds) {
    const hours = Math.ceil(seconds / 3600);
    return hours <= 1 ? 'за годину' : `приблизно за ${hours} год`;
}

export function mountAdGenerator(root) {
    const listingId = root.dataset.listingId;
    const buttons = [...root.querySelectorAll('[data-platform]')];
    const wsStateEl = root.querySelector('#ws-state');
    const noticeEl = root.querySelector('#ad-notice');
    const statusEl = root.querySelector('#ad-status');
    const resultEl = root.querySelector('#ad-result');
    const titleEl = root.querySelector('#ad-title');
    const bodyEl = root.querySelector('#ad-body');
    const copyButton = root.querySelector('#ad-copy');

    let session = null;

    const ui = {
        status(text) {
            statusEl.textContent = text;
        },
        notice(kind, level, text) {
            noticeEl.className = `mt-4 rounded border px-3 py-2 text-sm ${NOTICE_STYLES[level]}`;
            noticeEl.dataset.kind = kind;
            noticeEl.textContent = text;
        },
        clearNotice() {
            noticeEl.className = 'mt-4 hidden rounded border px-3 py-2 text-sm';
            delete noticeEl.dataset.kind;
            noticeEl.textContent = '';
        },
        wsState(state) {
            const [text, classes] = WS_STATES[state];
            wsStateEl.dataset.state = state;
            wsStateEl.className = `rounded-full px-2.5 py-0.5 text-xs ${classes}`;
            wsStateEl.textContent = text;
        },
        text(title, body) {
            resultEl.classList.toggle('hidden', title === '' && body === '');
            titleEl.textContent = title;
            bodyEl.textContent = body;
        },
        busy(isBusy) {
            for (const button of buttons) {
                button.disabled = isBusy;
            }
        },
        copyable(isCopyable) {
            copyButton.classList.toggle('hidden', !isCopyable);
        },
    };

    copyButton.addEventListener('click', async () => {
        const text = `${titleEl.textContent}\n\n${bodyEl.textContent}`;
        try {
            await navigator.clipboard.writeText(text);
            copyButton.textContent = 'Скопійовано';
        } catch {
            copyButton.textContent = 'Не вдалося скопіювати';
        }
        setTimeout(() => { copyButton.textContent = 'Скопіювати'; }, 2000);
    });

    for (const button of buttons) {
        button.addEventListener('click', () => start(button.dataset.platform));
    }

    async function start(platform) {
        session?.finish();
        session = null;

        ui.busy(true);
        ui.clearNotice();
        ui.copyable(false);
        ui.text('', '');
        ui.status('Ставлю в чергу…');

        const res = await fetch(`/api/listings/${encodeURIComponent(listingId)}/ads`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ platform }),
        }).catch(() => null);

        if (!res || res.status !== 202) {
            ui.busy(false);
            ui.status('');
            await rejectRequest(res);
            return;
        }

        const { data, meta } = await res.json();
        ui.status('У черзі…');
        session = new GenerationSession(data, meta, ui);
    }

    async function rejectRequest(res) {
        if (!res) {
            ui.notice('error', 'error', 'Сервер недоступний. Перевірте з\'єднання.');
            return;
        }

        // Помилки API — Problem Details (RFC 9457): розгалужуємося за машиночитним `code`, а не за статусом
        // (503 буває і «бюджет вичерпано», і «черга недоступна») чи текстом `detail`.
        const { code } = await res.json().catch(() => ({}));
        const retryAfter = Number(res.headers.get('Retry-After'));

        switch (code) {
            case 'ai_budget_exhausted':
                ui.notice('budget', 'warning',
                    `Бюджет AI на генерацію вичерпано. Спробуйте ${hoursUntil(retryAfter)}. Пошук при цьому працює.`);
                break;
            case 'queue_unavailable':
                ui.notice('queue', 'error', 'Черга генерації тимчасово недоступна. Спробуйте за хвилину.');
                break;
            case 'rate_limited':
                ui.notice('rate-limit', 'warning',
                    `Забагато генерацій поспіль.${retryAfter > 0 ? ` Спробуйте за ${Math.ceil(retryAfter)} с.` : ''}`);
                break;
            case 'ad_generation_in_progress':
                ui.notice('in-progress', 'warning', 'Для цієї квартири й майданчика генерація вже триває. Зачекайте на неї.');
                break;
            case 'not_found':
                ui.notice('not-found', 'error', 'Квартиру знято з публікації.');
                break;
            default:
                ui.notice('error', 'error', 'Не вдалося запустити генерацію.');
        }
    }
}

/**
 * Куди підключатися — із meta `reverb-config` (runtime), а не з VITE_* (вшито у збірку).
 * host = null → той самий origin, що й сторінка (тунель / реверс-проксі проксіює /app/* у Reverb).
 */
function reverbConnection() {
    const raw = document.querySelector('meta[name="reverb-config"]')?.content;
    const config = raw ? JSON.parse(raw) : {};
    const sameOrigin = !config.host;
    const scheme = sameOrigin ? window.location.protocol.replace(':', '') : (config.scheme ?? 'https');
    const tls = scheme === 'https';
    const port = sameOrigin
        ? Number(window.location.port || (tls ? 443 : 80))
        : Number(config.port ?? (tls ? 443 : 80));

    return {
        key: config.key ?? '',
        wsHost: sameOrigin ? window.location.hostname : config.host,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
    };
}

function makeEcho(meta) {
    return new Echo({
        broadcaster: 'reverb',
        Pusher,
        ...reverbConnection(),
        enabledTransports: ['ws', 'wss'],
        authEndpoint: meta.auth_endpoint,
        auth: { headers: { [meta.token_header]: meta.access_token, Accept: 'application/json' } },
    });
}

class GenerationSession {
    constructor(ad, meta, ui) {
        this.ad = ad;
        this.meta = meta;
        this.ui = ui;
        this.attempt = 0;
        this.seq = 0;
        this.broken = false;
        this.text = '';
        this.done = false;
        this.connected = false;
        this.wasConnected = false;
        this.pollTimer = null;

        ui.wsState('connecting');
        this.echo = makeEcho(meta);
        this.schedulePoll();

        this.echo.private(ad.channel)
            .subscribed(() => this.snapshot())
            .error(() => {
                this.ui.status('Не вдалося підписатися на канал. Результат підтягнеться через GET.');
                this.setConnected(false, 'unavailable');
            })
            .listen('.ad.started', (e) => this.startAttempt(e.attempt))
            .listen('.ad.chunk', (e) => this.onChunk(e))
            .listen('.ad.completed', () => this.snapshot())
            .listen('.ad.failed', () => this.snapshot());

        // Після обриву WebSocket події за час розриву втрачено: беремо знімок одразу після перепідключення.
        this.echo.connector.pusher.connection.bind('state_change', ({ current }) => {
            if (this.done) {
                return;
            }
            if (current === 'connected') {
                // Перше підключення знімок робить subscribed(); тут — лише після обриву.
                const reconnected = this.wasConnected;
                this.wasConnected = true;
                this.setConnected(true, 'connected');
                if (reconnected) {
                    this.snapshot();
                }
            } else if (current === 'unavailable' || current === 'failed') {
                this.setConnected(false, 'unavailable');
            } else if (this.wasConnected) {
                this.setConnected(false, 'lost');
            }
        });
    }

    setConnected(connected, state) {
        const changed = this.connected !== connected;
        this.connected = connected;
        this.ui.wsState(state);
        if (changed) {
            this.schedulePoll(); // інтервал опитування залежить від стану з'єднання
        }
    }

    schedulePoll() {
        clearTimeout(this.pollTimer);
        if (this.done) {
            return;
        }
        this.pollTimer = setTimeout(async () => {
            await this.snapshot();
            this.schedulePoll();
        }, this.connected ? POLL_CONNECTED_MS : POLL_DISCONNECTED_MS);
    }

    startAttempt(attempt) {
        this.attempt = attempt;
        this.seq = 0;
        this.broken = false;
        this.text = '';
        this.ui.text('', '');
        this.ui.status(attempt > 1 ? `Повторна спроба №${attempt}…` : 'Генерую…');
    }

    onChunk(e) {
        if (this.done || e.attempt < this.attempt) {
            return; // запізнілий чанк попередньої спроби
        }

        if (e.attempt > this.attempt) {
            // started цієї спроби ми пропустили (підписались пізніше).
            this.startAttempt(e.attempt);
            this.broken = e.seq !== 1;
        }

        if (this.broken || e.seq !== this.seq + 1) {
            this.broken = true;
            this.ui.status('Частину стріму пропущено. Чекаю на фінальний результат…');
            return;
        }

        this.seq = e.seq;
        this.text += e.text;
        this.ui.text('', this.text);
    }

    async snapshot() {
        if (this.done) {
            return;
        }

        const res = await fetch(`/api/listing-ads/${encodeURIComponent(this.ad.id)}`, {
            headers: { Accept: 'application/json', [this.meta.token_header]: this.meta.access_token },
        }).catch(() => null);

        if (this.done) {
            return;
        }

        // 404: генерації більше немає (квартиру видалено разом із нею) або токен не підходить.
        // Далі опитувати марно — без цього сторінка смикала GET кожні 3–10 с назавжди (День 13).
        if (res?.status === 404) {
            this.finish();
            this.ui.text('', '');
            this.ui.notice('gone', 'error', 'Генерацію не знайдено: можливо, квартиру знято з публікації.');
            this.ui.status('');
            return;
        }

        if (!res || !res.ok) {
            return;
        }

        const { data } = await res.json().catch(() => ({ data: null }));

        // Два знімки можуть летіти паралельно (опитування + ad.completed): фінал обробляємо один раз.
        if (this.done || data === null) {
            return;
        }

        if (data.status === 'completed') {
            this.finish();
            this.ui.text(data.title ?? '', data.body ?? '');
            this.ui.copyable(true);
            this.ui.status(`Готово · ${data.model} · спроб: ${data.attempt}`);
        } else if (data.status === 'failed') {
            this.finish();
            this.ui.text('', '');
            const message = `Не вдалося згенерувати: ${FAILURE_TEXT[data.failure_code] ?? data.failure_code}`;
            if (data.failure_code === 'budget_exceeded') {
                this.ui.notice('budget', 'warning', message);
            } else {
                this.ui.notice('failed', 'error', message);
            }
            this.ui.status('');
        } else if (data.status === 'queued') {
            this.ui.status('У черзі… (перший запуск локальної моделі може тривати до 1,5 хв)');
        } else if (data.status === 'processing' && this.text === '' && !this.broken) {
            this.ui.status(this.connected ? 'Генерую…' : 'Генерую… (текст з\'явиться, щойно завершиться)');
        }
    }

    finish() {
        this.done = true;
        clearTimeout(this.pollTimer);
        this.echo.disconnect();
        this.ui.wsState('idle');
        this.ui.busy(false);
    }
}

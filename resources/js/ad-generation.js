/**
 * Демо-клієнт генерації оголошення.
 *
 * WebSocket — лише швидкий канал без гарантій доставки, джерело істини — GET /api/listing-ads/{id}:
 *  - після підписки й після кожного перепідключення беремо знімок через GET;
 *  - пропуск у `seq` → перестаємо дописувати текст і чекаємо фінального результату;
 *  - нова спроба (`attempt`) → очищаємо текст попередньої;
 *  - рідке опитування GET страхує від втраченої події completed/failed.
 * Весь текст виводиться лише через textContent: відповідь LLM — недовірені дані.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const POLL_INTERVAL_MS = 10_000;

const form = document.getElementById('ad-form');
const statusEl = document.getElementById('ad-status');
const titleEl = document.getElementById('ad-title');
const bodyEl = document.getElementById('ad-body');
const button = form.querySelector('button');

const FAILURE_TEXT = {
    provider_unavailable: 'AI-провайдер недоступний.',
    invalid_output: 'Модель відповіла у неправильному форматі.',
    timeout: 'Генерація тривала задовго.',
    listing_unavailable: 'Квартира більше недоступна.',
    queue_unavailable: 'Черга недоступна.',
    stale: 'Генерація зависла.',
    unknown: 'Невідома помилка.',
};

let session = null;

function setStatus(text) {
    statusEl.textContent = text;
}

function makeEcho(meta) {
    return new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: meta.auth_endpoint,
        auth: { headers: { [meta.token_header]: meta.access_token, Accept: 'application/json' } },
    });
}

class GenerationSession {
    constructor(ad, meta) {
        this.ad = ad;
        this.meta = meta;
        this.attempt = 0;
        this.seq = 0;
        this.broken = false;
        this.text = '';
        this.done = false;
        this.echo = makeEcho(meta);
        this.poller = setInterval(() => this.snapshot(), POLL_INTERVAL_MS);

        const channel = this.echo.private(ad.channel);

        channel
            .subscribed(() => this.snapshot())
            .error(() => setStatus('Не вдалося підписатися на канал. Результат підтягнеться через опитування.'))
            .listen('.ad.started', (e) => this.startAttempt(e.attempt))
            .listen('.ad.chunk', (e) => this.onChunk(e))
            .listen('.ad.completed', () => this.snapshot())
            .listen('.ad.failed', () => this.snapshot());

        // Після обриву WebSocket події за час розриву втрачено: беремо знімок.
        let wasDisconnected = false;
        this.echo.connector.pusher.connection.bind('state_change', ({ current }) => {
            if (current === 'connected' && wasDisconnected) {
                this.snapshot();
            }
            wasDisconnected = current !== 'connected';
        });
    }

    startAttempt(attempt) {
        this.attempt = attempt;
        this.seq = 0;
        this.broken = false;
        this.text = '';
        titleEl.textContent = '';
        bodyEl.textContent = '';
        setStatus(attempt > 1 ? `Повторна спроба №${attempt}…` : 'Генерую…');
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
            setStatus('Частину стріму пропущено. Чекаю на фінальний результат…');
            return;
        }

        this.seq = e.seq;
        this.text += e.text;
        bodyEl.textContent = this.text;
    }

    async snapshot() {
        if (this.done) {
            return;
        }

        const res = await fetch(`/api/listing-ads/${this.ad.id}`, {
            headers: { Accept: 'application/json', [this.meta.token_header]: this.meta.access_token },
        }).catch(() => null);

        if (!res || !res.ok) {
            return;
        }

        const { data } = await res.json();

        if (data.status === 'completed') {
            this.finish();
            titleEl.textContent = data.title;
            bodyEl.textContent = data.body;
            setStatus(`Готово (${data.model}, спроб: ${data.attempt}).`);
        } else if (data.status === 'failed') {
            this.finish();
            setStatus(`Не вдалося згенерувати: ${FAILURE_TEXT[data.failure_code] ?? data.failure_code}`);
        } else if (data.status === 'queued') {
            setStatus('У черзі… (перший запуск локальної моделі може тривати до 1,5 хв)');
        }
    }

    finish() {
        this.done = true;
        clearInterval(this.poller);
        this.echo.disconnect();
        button.disabled = false;
    }
}

const ERRORS = {
    404: 'Квартиру не знайдено або вона не опублікована.',
    409: 'Для цієї квартири й майданчика генерація вже триває.',
    422: 'Некоректні параметри.',
    429: 'Забагато запитів. Спробуйте пізніше.',
    503: 'Генерація тимчасово недоступна.',
};

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    session?.finish();

    const listingId = form.elements.listing.value;
    const platform = form.elements.platform.value;

    button.disabled = true;
    titleEl.textContent = '';
    bodyEl.textContent = '';
    setStatus('Ставлю в чергу…');

    const res = await fetch(`/api/listings/${listingId}/ads`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ platform }),
    }).catch(() => null);

    if (!res || res.status !== 202) {
        button.disabled = false;
        setStatus(ERRORS[res?.status] ?? 'Сервер недоступний.');
        return;
    }

    const { data, meta } = await res.json();
    setStatus('У черзі…');
    session = new GenerationSession(data, meta);
});

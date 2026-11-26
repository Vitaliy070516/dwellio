import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flush, jsonResponse, loadFixture } from './helpers.js';

/*
 * Генерація оголошення (resources/js/ad-generation.js): стрім, обриви WebSocket і помилки API.
 * Echo підмінено фейком: тест сам «надсилає» події каналу й перемикає стан з'єднання Pusher.
 */

const echo = vi.hoisted(() => ({ instances: [] }));

vi.mock('pusher-js', () => ({ default: class {} }));
vi.mock('laravel-echo', () => ({
    default: class FakeEcho {
        constructor(options) {
            this.options = options;
            this.handlers = {};
            this.callbacks = {};
            this.stateListeners = [];
            this.disconnected = false;
            const fake = this;
            const channel = {
                subscribed(cb) { fake.callbacks.subscribed = cb; return channel; },
                error(cb) { fake.callbacks.error = cb; return channel; },
                listen(event, cb) { fake.handlers[event] = cb; return channel; },
            };
            this.channel = channel;
            this.connector = {
                pusher: { connection: { bind: (event, cb) => fake.stateListeners.push(cb) } },
            };
            echo.instances.push(this);
        }

        private(name) {
            this.channelName = name;
            return this.channel;
        }

        disconnect() {
            this.disconnected = true;
        }

        emit(event, payload = {}) {
            this.handlers[event]?.(payload);
        }

        state(current) {
            for (const listener of this.stateListeners) {
                listener({ current });
            }
        }
    },
}));

const { mountAdGenerator } = await import('../../resources/js/ad-generation.js');

const AD = { id: '0b5c2a52-3f5e-4b1e-9d6a-7a0f4f8d1c11', channel: 'listing-ads.0b5c2a52-3f5e-4b1e-9d6a-7a0f4f8d1c11', status: 'queued' };
const META = { access_token: 'secret-token', token_header: 'X-Ad-Token', auth_endpoint: 'http://localhost/api/broadcasting/auth' };

let fetchMock;
let snapshots;

const $ = (selector) => document.querySelector(selector);
const notice = () => $('#ad-notice').dataset.kind;
const getCalls = () => fetchMock.mock.calls.filter(([url, options]) => (options?.method ?? 'GET') === 'GET');
const currentEcho = () => echo.instances.at(-1);

function adSnapshot(overrides = {}) {
    return jsonResponse(200, { data: { ...AD, status: 'processing', attempt: 1, title: null, body: null, failure_code: null, model: 'qwen2.5:3b', ...overrides } });
}

/** POST-відповідь задається тестом; GET віддає наступний знімок із черги (або «processing»). */
function routeFetch(postResponse) {
    fetchMock = vi.fn(async (url, options = {}) => {
        if (options.method === 'POST') {
            if (postResponse instanceof Error) {
                throw postResponse;
            }
            return postResponse;
        }
        return snapshots.shift() ?? adSnapshot();
    });
    vi.stubGlobal('fetch', fetchMock);
}

async function startGeneration(postResponse = jsonResponse(202, { data: AD, meta: META })) {
    routeFetch(postResponse);
    $('[data-platform="olx"]').click();
    await flush();
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    echo.instances.length = 0;
    snapshots = [];
    loadFixture('listing');
    mountAdGenerator($('#ad-generator'));
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('request rejected by the API (branch on Problem Details `code`)', () => {
    it.each([
        ['budget', jsonResponse(503, { code: 'ai_budget_exhausted' }, { 'Retry-After': '7200' }), 'приблизно за 2 год'],
        ['queue', jsonResponse(503, { code: 'queue_unavailable' }), 'Черга генерації'],
        ['rate-limit', jsonResponse(429, { code: 'rate_limited' }, { 'Retry-After': '30' }), '30 с'],
        ['in-progress', jsonResponse(409, { code: 'ad_generation_in_progress' }), 'вже триває'],
        ['not-found', jsonResponse(404, { code: 'not_found' }), 'знято з публікації'],
        ['error', new Response('<html>502</html>', { status: 502 }), 'Не вдалося запустити'],
        ['error', new TypeError('Failed to fetch'), 'Сервер недоступний'],
    ])('%s', async (kind, response, text) => {
        await startGeneration(response);

        expect(notice()).toBe(kind);
        expect($('#ad-notice').textContent).toContain(text);
        // Кнопки знову доступні, WebSocket не відкривали.
        expect($('[data-platform="olx"]').disabled).toBe(false);
        expect(echo.instances).toHaveLength(0);
    });
});

describe('streaming over WebSocket', () => {
    it('passes the one-time token to channel auth, not to the page', async () => {
        await startGeneration();

        expect(currentEcho().channelName).toBe(AD.channel);
        expect(currentEcho().options.auth.headers['X-Ad-Token']).toBe('secret-token');
        expect($('[data-platform="olx"]').disabled).toBe(true);
    });

    it('appends chunks in seq order and takes the final text from GET', async () => {
        await startGeneration();
        const ws = currentEcho();
        ws.emit('.ad.started', { attempt: 1 });
        ws.emit('.ad.chunk', { attempt: 1, seq: 1, text: 'Тиха ' });
        ws.emit('.ad.chunk', { attempt: 1, seq: 2, text: 'квартира' });
        expect($('#ad-body').textContent).toBe('Тиха квартира');

        // Джерело істини — БД: фінальний текст (вже розібраний на title/body) береться з GET.
        snapshots.push(adSnapshot({ status: 'completed', title: 'Заголовок', body: 'Тиха квартира біля метро' }));
        ws.emit('.ad.completed');
        await flush();

        expect($('#ad-title').textContent).toBe('Заголовок');
        expect($('#ad-body').textContent).toBe('Тиха квартира біля метро');
        expect($('#ad-copy').classList.contains('hidden')).toBe(false);
        expect(ws.disconnected).toBe(true);
        expect($('#ws-state').dataset.state).toBe('idle');
        expect($('[data-platform="olx"]').disabled).toBe(false);
    });

    it('stops appending after a gap in seq and waits for the final result', async () => {
        await startGeneration();
        const ws = currentEcho();
        ws.emit('.ad.started', { attempt: 1 });
        ws.emit('.ad.chunk', { attempt: 1, seq: 1, text: 'Тиха ' });
        ws.emit('.ad.chunk', { attempt: 1, seq: 3, text: 'метро' });
        ws.emit('.ad.chunk', { attempt: 1, seq: 4, text: '!!!' });

        expect($('#ad-body').textContent).toBe('Тиха ');
        expect($('#ad-status').textContent).toContain('пропущено');
    });

    it('clears the text of a failed attempt when a retry starts and ignores its late chunks', async () => {
        await startGeneration();
        const ws = currentEcho();
        ws.emit('.ad.chunk', { attempt: 1, seq: 1, text: 'Стара спроба' });
        ws.emit('.ad.started', { attempt: 2 });
        ws.emit('.ad.chunk', { attempt: 1, seq: 2, text: ' запізнілий чанк' });
        ws.emit('.ad.chunk', { attempt: 2, seq: 1, text: 'Нова' });

        expect($('#ad-body').textContent).toBe('Нова');
        expect($('#ad-status').textContent).toContain('№2');
    });

    it('treats chunks as broken when it subscribed after the stream had started', async () => {
        await startGeneration();
        currentEcho().emit('.ad.chunk', { attempt: 1, seq: 5, text: 'середина' });

        expect($('#ad-body').textContent).toBe('');
        expect($('#ad-status').textContent).toContain('пропущено');
    });
});

describe('WebSocket loss and GET fallback', () => {
    it('shows the lost state and polls GET every 3 s instead of 10 s', async () => {
        await startGeneration();
        const ws = currentEcho();
        ws.state('connected');
        expect($('#ws-state').dataset.state).toBe('connected');

        ws.state('connecting'); // обрив після успішного підключення
        expect($('#ws-state').dataset.state).toBe('lost');

        const before = getCalls().length;
        await vi.advanceTimersByTimeAsync(3_000);
        expect(getCalls().length).toBe(before + 1);
    });

    it('polls rarely while connected', async () => {
        await startGeneration();
        currentEcho().state('connected');
        const before = getCalls().length;

        await vi.advanceTimersByTimeAsync(9_000);
        expect(getCalls().length).toBe(before);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(getCalls().length).toBe(before + 1);
    });

    it('takes a snapshot right after reconnecting: events during the gap are lost', async () => {
        await startGeneration();
        const ws = currentEcho();
        ws.state('connected');
        ws.state('connecting');
        const before = getCalls().length;

        snapshots.push(adSnapshot({ status: 'completed', title: 'Готово', body: 'Текст' }));
        ws.state('connected');
        await flush();

        expect(getCalls().length).toBe(before + 1);
        expect($('#ad-body').textContent).toBe('Текст');
    });

    it('finishes via GET polling alone when WebSocket is unavailable', async () => {
        await startGeneration();
        currentEcho().state('unavailable');
        expect($('#ws-state').dataset.state).toBe('unavailable');

        snapshots.push(adSnapshot({ status: 'completed', title: 'Через GET', body: 'Текст' }));
        await vi.advanceTimersByTimeAsync(3_000);
        await flush();

        expect($('#ad-title').textContent).toBe('Через GET');
    });

    it('keeps polling through transient GET errors', async () => {
        await startGeneration();
        snapshots.push(new Response('', { status: 500 }), new Response('', { status: 502 }));
        await vi.advanceTimersByTimeAsync(6_000);

        snapshots.push(adSnapshot({ status: 'completed', title: 'Дочекались', body: 'Текст' }));
        await vi.advanceTimersByTimeAsync(3_000);
        await flush();

        expect($('#ad-title').textContent).toBe('Дочекались');
    });

    it('stops polling when the generation is gone (404) instead of polling forever (Day 13)', async () => {
        await startGeneration();
        snapshots.push(new Response('', { status: 404 }));
        await vi.advanceTimersByTimeAsync(3_000);
        await flush();

        expect(notice()).toBe('gone');
        const after = getCalls().length;
        await vi.advanceTimersByTimeAsync(60_000);
        expect(getCalls().length).toBe(after);
        expect($('[data-platform="olx"]').disabled).toBe(false);
    });
});

describe('final failure', () => {
    it.each([
        ['budget_exceeded', 'budget', 'Бюджет AI вичерпано'],
        ['provider_unavailable', 'failed', 'AI-провайдер недоступний'],
        ['something_new', 'failed', 'something_new'],
    ])('%s', async (failureCode, kind, text) => {
        await startGeneration();
        snapshots.push(adSnapshot({ status: 'failed', failure_code: failureCode }));
        currentEcho().emit('.ad.failed');
        await flush();

        expect(notice()).toBe(kind);
        expect($('#ad-notice').textContent).toContain(text);
        expect(currentEcho().disconnected).toBe(true);
    });
});

it('renders LLM output as text, never as HTML', async () => {
    await startGeneration();
    currentEcho().emit('.ad.chunk', { attempt: 1, seq: 1, text: '<img src=x onerror=alert(1)>' });
    snapshots.push(adSnapshot({ status: 'completed', title: '<b>x</b>', body: '<script>alert(1)</script>' }));
    currentEcho().emit('.ad.completed');
    await flush();

    expect($('#ad-result img, #ad-result b, #ad-result script')).toBeNull();
    expect($('#ad-title').textContent).toBe('<b>x</b>');
});

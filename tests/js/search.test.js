import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { deferredFetch, flush, jsonResponse, loadFixture } from './helpers.js';

/*
 * Сторінка пошуку (resources/js/search.js): що бачить людина, коли щось деградувало.
 * Модуль виконується при імпорті (як на сторінці), тому кожен тест вантажить його наново.
 */

const LISTING = {
    id: 1,
    title: 'Тиха квартира',
    description: 'Біля метро, можна з котом.',
    price_uah: 18000,
    rooms: 2,
    area_m2: 54.5,
    floor: 3,
    floors_total: 9,
    district_label: 'Подільський',
    metro_station: 'Контрактова площа',
    metro_distance_m: 400,
    pets_allowed: true,
    furnished: false,
    match: { matched_by: ['vector', 'fts'], ranks: { vector: 1, fts: 3 }, score: 0.03 },
};

function meta(overrides = {}) {
    return {
        mode: 'hybrid',
        parsed_by_llm: true,
        semantic_query: 'тиха квартира',
        filters: {
            price_min: null, price_max: null, rooms: [], districts: [],
            pets_allowed: null, furnished: null, max_metro_distance_m: null,
        },
        degraded: { query_parsing: null, embeddings: null },
        count: 1,
        ...overrides,
    };
}

let fetchMock;
let calls;

async function mountPage(url = '/') {
    history.replaceState(null, '', url);
    loadFixture('search');
    vi.resetModules();
    await import('../../resources/js/search.js');
}

function submit(query) {
    const form = document.getElementById('search-form');
    form.elements.q.value = query;
    form.requestSubmit();
}

const notices = () => [...document.querySelectorAll('#search-notices [data-kind]')].map((n) => n.dataset.kind);
const noticeText = () => document.getElementById('search-notices').textContent;
const submitButton = () => document.querySelector('#search-form button[type="submit"]');

beforeEach(() => {
    ({ fetchMock, calls } = deferredFetch());
    vi.stubGlobal('fetch', vi.fn(fetchMock));
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('degradation banners from meta.degraded', () => {
    it.each([
        ['budget on parsing', { query_parsing: 'budget_exceeded', embeddings: null }, 'text_only', ['budget']],
        // Бюджет вичерпано на обох кроках — один банер, а не два.
        ['budget on both steps', { query_parsing: 'budget_exceeded', embeddings: 'budget_exceeded' }, 'text_only', ['budget']],
        ['LLM down, vectors ok', { query_parsing: 'provider_unavailable', embeddings: null }, 'hybrid', ['no-ai']],
        ['LLM and embeddings down', { query_parsing: 'provider_unavailable', embeddings: 'provider_unavailable' }, 'text_only', ['no-ai', 'no-semantic']],
        ['only embeddings down', { query_parsing: null, embeddings: 'provider_unavailable' }, 'text_only', ['no-semantic']],
        ['everything works', { query_parsing: null, embeddings: null }, 'hybrid', []],
    ])('%s', async (_, degraded, mode, expected) => {
        await mountPage();
        submit('тиха квартира');
        calls[0].resolve(jsonResponse(200, { data: [LISTING], meta: meta({ mode, degraded }) }));
        await flush();

        expect(notices()).toEqual(expected);
    });

    it('tells an invalid LLM answer apart from an unavailable provider', async () => {
        await mountPage();
        submit('тиха квартира');
        calls[0].resolve(jsonResponse(200, {
            data: [], meta: meta({ degraded: { query_parsing: 'invalid_output', embeddings: null } }),
        }));
        await flush();

        expect(noticeText()).toContain('модель відповіла некоректно');
    });

    it('explains filters_only only when the user typed a query', async () => {
        await mountPage();
        submit('двокімнатна до 20 тисяч');
        calls[0].resolve(jsonResponse(200, { data: [LISTING], meta: meta({ mode: 'filters_only', semantic_query: '' }) }));
        await flush();
        expect(notices()).toEqual(['filters-only']);
    });
});

describe('errors (Problem Details, branch on `code`)', () => {
    it('shows Retry-After for rate limiting', async () => {
        await mountPage();
        submit('кіт');
        calls[0].resolve(jsonResponse(429, { code: 'rate_limited', status: 429 }, { 'Retry-After': '42' }));
        await flush();

        expect(notices()).toEqual(['rate-limit']);
        expect(noticeText()).toContain('42 с');
        expect(submitButton().disabled).toBe(false);
    });

    it('explains a network failure', async () => {
        await mountPage();
        submit('кіт');
        calls[0].reject(new TypeError('Failed to fetch'));
        await flush();

        expect(noticeText()).toContain('Сервер недоступний');
        expect(submitButton().disabled).toBe(false);
    });

    it('survives a non-JSON error page (e.g. 502 from the tunnel)', async () => {
        await mountPage();
        submit('кіт');
        calls[0].resolve(new Response('<html>Bad gateway</html>', { status: 502 }));
        await flush();

        expect(notices()).toEqual(['error']);
        expect(noticeText()).toContain('тимчасово недоступний');
    });

    it('survives a 200 with a broken body', async () => {
        await mountPage();
        submit('кіт');
        calls[0].resolve(new Response('{"data": [', { status: 200 }));
        await flush();

        expect(noticeText()).toContain('неочікувану відповідь');
        expect(submitButton().disabled).toBe(false);
    });
});

describe('races and navigation', () => {
    it('does not let a slow old response overwrite a fresh one', async () => {
        await mountPage();
        submit('старий запит');
        submit('новий запит');
        expect(calls).toHaveLength(2);

        calls[1].resolve(jsonResponse(200, { data: [{ ...LISTING, title: 'Свіжа' }], meta: meta() }));
        await flush();
        // Старий запит скасовано через AbortController: його відповідь сюди вже не дійде.
        expect(calls[0].options.signal.aborted).toBe(true);
        expect(document.querySelector('[data-field="title"]').textContent).toBe('Свіжа');
        expect(document.querySelectorAll('#search-results li')).toHaveLength(1);
    });

    it('re-enables the form when the user goes back to the empty page mid-search (Day 13 regression)', async () => {
        await mountPage();
        submit('кіт');
        expect(submitButton().disabled).toBe(true);

        history.pushState(null, '', '/');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await flush();

        expect(calls[0].options.signal.aborted).toBe(true);
        expect(submitButton().disabled).toBe(false);
    });

    it('runs the search from the URL on load, so a result link can be shared', async () => {
        await mountPage('/?q=%D0%BA%D1%96%D1%82&pets_allowed=1');

        expect(calls).toHaveLength(1);
        const api = new URL(calls[0].url, 'http://localhost');
        expect(api.pathname).toBe('/api/search');
        expect(api.searchParams.get('q')).toBe('кіт');
        expect(api.searchParams.get('pets_allowed')).toBe('1');
        expect(document.querySelector('details').open).toBe(true);
    });
});

it('renders untrusted listing data as text, never as HTML', async () => {
    await mountPage();
    submit('кіт');
    calls[0].resolve(jsonResponse(200, {
        data: [{ ...LISTING, title: '<img src=x onerror=alert(1)>', description: '<script>alert(2)</script>' }],
        meta: meta({ semantic_query: '<b>x</b>' }),
    }));
    await flush();

    expect(document.querySelector('#search-results img')).toBeNull();
    expect(document.querySelector('#search-results script')).toBeNull();
    expect(document.querySelector('#search-understood b')).toBeNull();
    expect(document.querySelector('[data-field="title"]').textContent).toBe('<img src=x onerror=alert(1)>');
});

/**
 * Сторінка пошуку: клієнт публічного GET /api/search.
 *
 *  - Стани деградації беремо з `meta.mode` + `meta.degraded`, а не вгадуємо: сервер каже, що саме не відпрацювало.
 *  - Параметри пошуку дублюються в URL сторінки (`/?q=…`): посилання на видачу можна переслати, «Назад» працює.
 *  - Новий пошук скасовує попередній запит (AbortController): інакше повільна стара відповідь
 *    могла б перезаписати свіжу (гонка відповідей).
 *  - Дані квартир і відповідь LLM — недовірені: у DOM лише через textContent, без innerHTML.
 */
const RESULTS_LIMIT = 20;

const form = document.getElementById('search-form');
const submitButton = form.querySelector('button[type="submit"]');
const noticesEl = document.getElementById('search-notices');
const understoodEl = document.getElementById('search-understood');
const summaryEl = document.getElementById('search-summary');
const resultsEl = document.getElementById('search-results');
const cardTemplate = document.getElementById('listing-card');
const districts = JSON.parse(document.getElementById('districts-data').textContent);

const FILTER_PARAMS = ['price_max', 'rooms', 'pets_allowed', 'furnished'];

const NOTICE_STYLES = {
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    error: 'border-red-300 bg-red-50 text-red-900',
};

const MATCH_LABELS = {
    vector: 'за змістом',
    fts: 'за словами',
    trigram: 'схожі слова',
};

let inFlight = null;

const formatter = new Intl.NumberFormat('uk-UA');

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

function addNotice(kind, level, text) {
    const notice = el('p', `rounded border px-3 py-2 text-sm ${NOTICE_STYLES[level]}`, text);
    notice.dataset.kind = kind;
    noticesEl.append(notice);
}

function clearOutput() {
    noticesEl.replaceChildren();
    understoodEl.replaceChildren();
    resultsEl.replaceChildren();
    summaryEl.textContent = '';
}

/** Форма → query string для API і для адреси сторінки (одна й та сама). */
function paramsFromForm() {
    const params = new URLSearchParams();
    const q = form.elements.q.value.trim();
    if (q !== '') {
        params.set('q', q);
    }
    if (form.elements.price_max.value !== '') {
        params.set('price_max', form.elements.price_max.value);
    }
    if (form.elements.rooms.value !== '') {
        params.set('rooms', form.elements.rooms.value);
    }
    // Не позначено = «байдуже» (null), а не «не можна»: тому параметр просто не передаємо.
    for (const name of ['pets_allowed', 'furnished']) {
        if (form.elements[name].checked) {
            params.set(name, '1');
        }
    }
    return params;
}

function fillForm(params) {
    form.elements.q.value = params.get('q') ?? '';
    form.elements.price_max.value = params.get('price_max') ?? '';
    form.elements.rooms.value = params.get('rooms') ?? '';
    form.elements.pets_allowed.checked = params.get('pets_allowed') === '1';
    form.elements.furnished.checked = params.get('furnished') === '1';
    if (FILTER_PARAMS.some((name) => params.has(name))) {
        form.querySelector('details').open = true;
    }
}

function apiQuery(params) {
    const api = new URLSearchParams();
    for (const [key, value] of params) {
        // API приймає rooms як масив (rooms[]=2), сторінка тримає один select.
        api.append(key === 'rooms' ? 'rooms[]' : key, value);
    }
    api.set('limit', String(RESULTS_LIMIT));
    return api;
}

/** Що саме деградувало — з `meta`. Бюджет показуємо одним банером, навіть якщо вичерпано обидва кроки. */
function renderDegradation(meta, hasQuery) {
    const { query_parsing: parsing, embeddings } = meta.degraded ?? {};

    if (parsing === 'budget_exceeded' || embeddings === 'budget_exceeded') {
        addNotice('budget', 'warning',
            'Денний бюджет AI вичерпано. Пошук працює без AI (лише збіг слів і точні фільтри) до півночі за UTC.');
        return;
    }

    if (parsing !== null && parsing !== undefined) {
        addNotice('no-ai', 'warning', parsing === 'invalid_output'
            ? 'Пошук без AI: модель відповіла некоректно, тож запит не розібрано на фільтри. Шукаю за всім текстом.'
            : 'Пошук без AI: модель недоступна, тож запит не розібрано на фільтри. Шукаю за всім текстом.');
    }

    if (meta.mode === 'text_only') {
        addNotice('no-semantic', 'info',
            'Семантичний пошук тимчасово недоступний: знаходжу лише за збігом слів, синоніми й «схоже за змістом» не спрацюють.');
    } else if (meta.mode === 'filters_only' && hasQuery) {
        addNotice('filters-only', 'info',
            'Запит повністю перетворився на фільтри — показую всі квартири, що їм відповідають, від найновіших.');
    }
}

function filterChips(filters) {
    const chips = [];
    if (filters.price_min !== null) {
        chips.push(`від ${formatter.format(filters.price_min)} грн`);
    }
    if (filters.price_max !== null) {
        chips.push(`до ${formatter.format(filters.price_max)} грн`);
    }
    if (filters.rooms.length > 0) {
        chips.push(`кімнат: ${filters.rooms.join(', ')}`);
    }
    for (const slug of filters.districts) {
        chips.push(`${districts[slug] ?? slug} р-н`);
    }
    if (filters.max_metro_distance_m !== null) {
        chips.push(`метро ≤ ${filters.max_metro_distance_m} м`);
    }
    if (filters.pets_allowed !== null) {
        chips.push(filters.pets_allowed ? 'з тваринами' : 'без тварин');
    }
    if (filters.furnished !== null) {
        chips.push(filters.furnished ? 'з меблями' : 'без меблів');
    }
    return chips;
}

/** «Як AI зрозумів запит» — головний вау-момент демо і найшвидший спосіб побачити помилку розбору. */
function renderUnderstanding(meta) {
    const chips = filterChips(meta.filters);
    if (chips.length === 0 && meta.semantic_query === '') {
        return;
    }

    understoodEl.append(el('span', 'text-stone-500', meta.parsed_by_llm ? 'AI зрозумів:' : 'Фільтри:'));
    for (const chip of chips) {
        understoodEl.append(el('span', 'rounded-full bg-stone-900 px-2.5 py-0.5 text-white', chip));
    }
    if (meta.semantic_query !== '') {
        understoodEl.append(el('span', 'rounded-full bg-stone-200 px-2.5 py-0.5', `за змістом: «${meta.semantic_query}»`));
    }
}

function truncate(text, max) {
    return text.length > max ? `${text.slice(0, max).trimEnd()}…` : text;
}

function renderCard(listing) {
    const card = cardTemplate.content.firstElementChild.cloneNode(true);
    const field = (name) => card.querySelector(`[data-field="${name}"]`);

    field('link').href = `/listings/${encodeURIComponent(listing.id)}`;
    field('title').textContent = listing.title;
    field('price').textContent = formatter.format(listing.price_uah);
    field('facts').textContent =
        `${listing.rooms} кімн. · ${listing.area_m2} м² · ${listing.floor}/${listing.floors_total} пов.`;
    field('place').textContent = [
        `${listing.district_label} р-н`,
        listing.metro_station ? `м. ${listing.metro_station}${listing.metro_distance_m !== null ? ` · ${listing.metro_distance_m} м` : ''}` : null,
    ].filter(Boolean).join(' · ');
    field('description').textContent = truncate(listing.description, 180);

    const badges = field('badges');
    if (listing.pets_allowed) {
        badges.append(el('span', 'rounded bg-emerald-50 px-2 py-0.5 text-emerald-800', 'можна з тваринами'));
    }
    if (listing.furnished) {
        badges.append(el('span', 'rounded bg-emerald-50 px-2 py-0.5 text-emerald-800', 'з меблями'));
    }
    // Пояснення збігу: яка гілка гібридного пошуку знайшла квартиру і на якому місці.
    for (const source of listing.match?.matched_by ?? []) {
        const badge = el('span', 'rounded bg-sky-50 px-2 py-0.5 text-sky-800',
            `${MATCH_LABELS[source] ?? source} #${listing.match.ranks[source]}`);
        badge.dataset.match = source;
        badges.append(badge);
    }

    return card;
}

function renderResult({ data, meta }, hasQuery) {
    renderDegradation(meta, hasQuery);
    renderUnderstanding(meta);

    if (data.length === 0) {
        summaryEl.textContent = 'Нічого не знайдено. Спробуйте прибрати частину умов — кожен фільтр жорсткий.';
        return;
    }

    summaryEl.textContent = `Знайдено: ${data.length}${data.length === RESULTS_LIMIT ? ' (показано перші)' : ''}`;
    resultsEl.replaceChildren(...data.map(renderCard));
}

function retryAfterText(response) {
    const seconds = Number(response.headers.get('Retry-After'));
    return Number.isFinite(seconds) && seconds > 0 ? ` Спробуйте за ${Math.ceil(seconds)} с.` : '';
}

async function renderError(response) {
    if (response === null) {
        addNotice('error', 'error', 'Сервер недоступний. Перевірте з\'єднання і спробуйте ще раз.');
        return;
    }

    // Problem Details (RFC 9457): розгалуження за `code`, див. /docs/api, схема Problem.
    const body = await response.json().catch(() => ({}));

    if (body.code === 'validation_failed') {
        addNotice('error', 'error', body.errors?.q
            ? 'Введіть запит (до 300 символів) або оберіть хоча б один фільтр.'
            : 'Перевірте фільтри: деякі значення некоректні.');
    } else if (body.code === 'rate_limited') {
        addNotice('rate-limit', 'warning', `Забагато пошуків поспіль.${retryAfterText(response)}`);
    } else {
        addNotice('error', 'error', 'Пошук тимчасово недоступний. Спробуйте трохи згодом.');
    }
}

async function search(params) {
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;

    clearOutput();
    if ([...params.keys()].length === 0) {
        return;
    }

    submitButton.disabled = true;
    summaryEl.textContent = 'Шукаю… (перший запит до локальної моделі може тривати до хвилини)';

    let response = null;
    try {
        response = await fetch(`/api/search?${apiQuery(params)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
    } catch (error) {
        if (error.name === 'AbortError') {
            return; // запит замінено новішим — він і відмалює результат
        }
    }

    if (controller !== inFlight) {
        return;
    }

    summaryEl.textContent = '';
    try {
        if (response !== null && response.ok) {
            renderResult(await response.json(), params.has('q'));
        } else {
            await renderError(response);
        }
    } catch {
        addNotice('error', 'error', 'Сервер повернув неочікувану відповідь.');
    } finally {
        if (controller === inFlight) {
            submitButton.disabled = false;
            inFlight = null;
        }
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    const params = paramsFromForm();
    const url = params.size > 0 ? `/?${params}` : '/';
    // Кожен пошук — запис в історії: «Назад» з картки квартири повертає саме цю видачу.
    if (`${location.pathname}${location.search}` !== url) {
        history.pushState(null, '', url);
    }
    search(params);
});

form.addEventListener('click', (event) => {
    const example = event.target.closest('[data-example]');
    if (example) {
        form.elements.q.value = example.dataset.example;
        form.requestSubmit();
    }
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(location.search);
    fillForm(params);
    search(params);
});

const initial = new URLSearchParams(location.search);
fillForm(initial);
search(paramsFromForm());

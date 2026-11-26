import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Від кореня проєкту (vitest запускається звідти): import.meta.url у jsdom-оточенні — не file://.
export function loadFixture(name) {
    document.body.innerHTML = readFileSync(resolve(process.cwd(), 'tests/js/fixtures', `${name}.html`), 'utf8');
}

/** Відповідь як від Laravel: JSON-тіло, статус і заголовки (Retry-After тощо). */
export function jsonResponse(status, body, headers = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json', ...headers },
    });
}

/** fetch, що поважає AbortSignal, як справжній: скасований запит падає з AbortError. */
export function deferredFetch() {
    const calls = [];
    const fetchMock = (url, options = {}) => new Promise((resolve, reject) => {
        const call = { url: String(url), options, resolve, reject };
        calls.push(call);
        options.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    });
    return { fetchMock, calls };
}

/**
 * Дати відпрацювати await-ам після fetch / json. setImmediate, а не setTimeout: тести генерації
 * підміняють таймери (опитування GET), а цей цикл має крутитися й тоді.
 */
export async function flush() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

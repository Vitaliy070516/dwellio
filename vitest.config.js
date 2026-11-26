import { defineConfig } from 'vitest/config';

// Окремо від vite.config.js: тестам не потрібні laravel-vite-plugin і Tailwind, лише DOM (jsdom).
export default defineConfig({
    test: {
        environment: 'jsdom',
        environmentOptions: { jsdom: { url: 'http://localhost/' } },
        include: ['tests/js/**/*.test.js'],
        restoreMocks: true,
    },
});

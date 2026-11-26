import { mountAdGenerator } from './ad-generation.js';

// «← До пошуку» веде на ту саму видачу, з якої прийшли (запит живе в URL сторінки пошуку).
const back = document.getElementById('back-link');
if (document.referrer.startsWith(`${location.origin}/?`)) {
    back.href = document.referrer;
}

mountAdGenerator(document.getElementById('ad-generator'));

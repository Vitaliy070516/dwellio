// Swagger UI зібрано Vite-ом із npm (swagger-ui-dist), а не з CDN: версія закріплена в package-lock,
// сторінка працює офлайн і не тягне сторонній JS у браузер (менше поверхні для supply-chain атаки).
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

const root = document.getElementById('swagger-ui');

SwaggerUIBundle({
    domNode: root,
    url: root.dataset.specUrl,
    deepLinking: true,
    // Try it out одразу: демо-API публічне, автентифікації немає (генерацію захищає X-Ad-Token).
    tryItOutEnabled: true,
    displayRequestDuration: true,
    defaultModelsExpandDepth: 0,
    docExpansion: 'list',
});

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/studio/main.js',
                // R-45 create flow: one entry per page script (each imports its own page CSS)
                'resources/js/studio/pages/start.js',
                'resources/js/studio/pages/brief.js',
                'resources/js/studio/pages/templates.js',
            ],
            refresh: true,
        }),
    ],
});

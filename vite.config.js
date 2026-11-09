import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/studio/main.js',
                // R-45 one entry per line so parallel branches merge cleanly
                'resources/css/pages/share.css',
                'resources/css/pages/reviews.css',
                'resources/css/pages/activity.css',
                'resources/js/studio/pages/share-viewer.js',
                'resources/js/studio/pages/start.js',
                'resources/js/studio/pages/brief.js',
                'resources/js/studio/pages/templates.js',
            ],
            refresh: true,
        }),
    ],
});

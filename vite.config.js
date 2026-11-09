import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/studio/main.js', 'resources/css/pages/share.css', 'resources/css/pages/reviews.css', 'resources/css/pages/activity.css', 'resources/js/studio/pages/share-viewer.js'],
            refresh: true,
        }),
    ],
});

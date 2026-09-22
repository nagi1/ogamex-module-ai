import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// The module owns its own build: sources live in Modules/AI/resources, only the built
// artifacts land under the host's public/ (R11). `publicDirectory` points at the host's
// public dir and `buildDirectory` namespaces the module's manifest, so the host's own
// `public/build/manifest.json` is never touched.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/ai-console.js'],
            publicDirectory: '../../public',
            buildDirectory: 'modules/ai/build',
            refresh: false,
        }),
    ],
});

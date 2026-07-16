import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // resources/css/app.css is its own entry so plain Blade pages
            // can pull in the compiled Tailwind CSS without loading the
            // Vue/Inertia bundle.
            input: ['resources/js/app.js', 'resources/css/app.css'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // Resolves the package's front-end sources out of the installed
            // dependency. With the composer.local.json path override active
            // (see README "Developing core and Community together"),
            // vendor/sendtrap/core is a symlink to a live core checkout, so
            // package .vue edits are seen immediately by the dev server and
            // the build.
            '@sendtrap/core': fileURLToPath(new URL('./vendor/sendtrap/core/resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

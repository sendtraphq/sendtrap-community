import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

/**
 * Plan 06 Phase 4b design §10.8: a minimal Vitest config for the frontend
 * smoke test — deliberately separate from vite.config.js (which pulls in
 * the laravel-vite-plugin, irrelevant under a Node test runner) but
 * mirroring its one load-bearing piece, the @sendtrap/core alias, so the
 * smoke test exercises the exact same module resolution the real build does.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@sendtrap/core': fileURLToPath(new URL('./vendor/sendtrap/core/resources/js', import.meta.url)),
        },
        // Under the composer.local.json path-repo override (README
        // "Developing core and Community together"), vendor/sendtrap/core is
        // a symlink to a live core checkout outside this project root, which
        // has no node_modules of its own (the package ships no JS
        // dependencies — see its own composer.json/absence of package.json).
        // Vite's default (false) resolves bare imports from the symlink's
        // REAL path, so a file under the symlinked tree importing
        // "@inertiajs/vue3" fails to resolve — there's no node_modules
        // anywhere above the real core checkout. preserveSymlinks keeps
        // resolution anchored at the symlink's apparent location instead,
        // which is under this project and finds this project's node_modules.
        preserveSymlinks: true,
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.smoke.test.js'],
    },
});

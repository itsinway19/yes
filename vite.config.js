import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { buildArtifact } from './compat/inertia-n1/build.mjs';

export default defineConfig(async () => {
    const artifact = await buildArtifact();
    return {
        resolve: { alias: [{ find: /^@inertiajs\/core$/, replacement: artifact.target }] },
        optimizeDeps: { exclude: ['@inertiajs/core', '@inertiajs/vue3'] },
        plugins: [
            laravel({
                input: 'resources/js/app.ts',
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
            tailwindcss(),
        ],
    };
});

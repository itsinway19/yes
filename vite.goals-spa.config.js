import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
    plugins: [laravel({ input: 'resources/js/goals-spa/main.ts', buildDirectory: 'build/goals-spa' }), vue(), tailwindcss()],
    build: { emptyOutDir: true },
});

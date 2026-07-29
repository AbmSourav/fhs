import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    // APP_PORT is the host port Caddy publishes; read it from .env so the CORS
    // allow-list follows whatever the developer configured.
    const appPort = loadEnv(mode, process.cwd(), 'APP_PORT').APP_PORT || '7080';

    return {
        server: {
            // Vite runs inside the `vite` container, so it must listen on all
            // interfaces — but the browser reaches it on the published host port.
            host: '0.0.0.0',
            port: 5173,
            // What Laravel writes into public/hot and what the HMR client dials.
            origin: 'http://localhost:5173',
            // The page is served by Caddy on APP_PORT, so assets fetched from
            // Vite are cross-origin and must be explicitly allowed.
            cors: {
                origin: [
                    `http://localhost:${appPort}`,
                    `http://127.0.0.1:${appPort}`,
                ],
            },
            hmr: {
                host: 'localhost',
                protocol: 'ws',
            },
            watch: {
                // Bind mounts don't deliver inotify events reliably on macOS.
                usePolling: true,
                interval: 300,
                ignored: ['**/vendor/**', '**/node_modules/**'],
            },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
            // SSR is not used here; without this the plugin warms an SSR module
            // graph and trips over browser-only code in use-appearance.
            inertia({ ssr: false }),
            react(),
            tailwindcss(),
        ],
    };
});

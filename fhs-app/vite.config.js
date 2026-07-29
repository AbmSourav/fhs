import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    // APP_PORT is the host port Caddy publishes; read it from .env so the CORS
    // allow-list follows whatever the developer configured.
    const env = loadEnv(mode, process.cwd(), 'APP_');
    const appPort = env.APP_PORT || '7080';

    // The app is reachable under its .test hostname as well as localhost, and
    // each is a distinct origin for CORS purposes. Derive the configured host
    // from APP_URL so a custom domain keeps working.
    const appHosts = ['localhost', '127.0.0.1'];

    try {
        const configuredHost = new URL(env.APP_URL).hostname;

        if (configuredHost && !appHosts.includes(configuredHost)) {
            appHosts.push(configuredHost);
        }
    } catch {
        // APP_URL unset or malformed — the localhost defaults still apply.
    }

    // Serve assets and HMR from the same hostname the page is browsed under, so
    // the websocket isn't blocked as cross-origin.
    const assetHost = appHosts.at(-1);

    return {
        server: {
            // Vite runs inside the `vite` container, so it must listen on all
            // interfaces — but the browser reaches it on the published host port.
            host: '0.0.0.0',
            port: 5173,
            // What Laravel writes into public/hot and what the HMR client dials.
            // Must be a hostname the browser can resolve, not the bind address.
            origin: `http://${assetHost}:5173`,
            // The page is served by Caddy on APP_PORT, so assets fetched from
            // Vite are cross-origin and must be explicitly allowed.
            cors: {
                origin: appHosts.map((host) => `http://${host}:${appPort}`),
            },
            hmr: {
                host: assetHost,
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

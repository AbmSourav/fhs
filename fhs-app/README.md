# FHS

Laravel + Inertia + React application. The whole local environment runs in
Docker — PHP, PostgreSQL, Redis and Vite all live in containers, so nothing
needs to be installed on the host except Docker itself.

## Stack

| Layer      | Choice                                     |
| ---------- | ------------------------------------------ |
| Backend    | Laravel 13, PHP 8.5 (php-fpm)              |
| Frontend   | React 19, Inertia 3, TypeScript, Tailwind 4 |
| Build      | Vite 8                                     |
| Database   | PostgreSQL 18                              |
| Cache/Queue| Redis 7 (cache store + queue driver)       |
| Web server | Caddy 2                                    |
| Auth       | Laravel Fortify                            |
| Queues UI  | Laravel Horizon                            |

## Getting started

```bash
cp .env.example .env          # then set UID/GID to match `id -u` / `id -g`
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

The `vite` container runs `npm install` and `npm run dev` on start, so the
frontend comes up on its own.

| Service      | URL                     |
| ------------ | ----------------------- |
| App          | http://localhost:7080   |
| Vite dev     | http://localhost:5173   |
| RedisInsight | http://localhost:5541   |
| PostgreSQL   | `localhost:5433`        |
| Redis        | `localhost:6380`        |

## Ports

Host ports are deliberately non-default so this stack can run alongside other
local projects. Inside the compose network the services still use their
standard ports (5432, 6379) — that's what `DB_PORT` and `REDIS_PORT` in `.env`
refer to. The `*_HOST_PORT` variables control only what is published to the
host, and `APP_PORT` additionally feeds the Vite CORS allow-list.

## Common commands

Everything runs inside the containers:

```bash
docker compose exec php php artisan migrate
docker compose exec php php artisan test
docker compose exec php php artisan tinker
docker compose exec php php artisan horizon      # queue worker + dashboard
docker compose exec vite npm run build
docker compose exec vite npx eslint .
```

## Notes

- **Xdebug** is installed but off. Set `XDEBUG_MODE=debug` in `.env` and restart
  the `php` container; it connects back to `host.docker.internal:9003`.
- **File ownership** — the `UID`/`GID` build args realign the container users to
  the host user so files written into the bind mount (`node_modules`,
  `public/build`, migrations) stay editable on the host.
- **Vite** listens on `0.0.0.0` inside its container but advertises
  `localhost:5173` to the browser, and allow-lists the Caddy origin for CORS.
  File watching uses polling, since bind mounts don't deliver inotify events
  reliably on macOS.
- **SSR is not enabled.** The Inertia Vite plugin is passed `ssr: false`.

FROM node:22-alpine

ARG UID=1000
ARG GID=1000

# The base image ships a `node` user at 1000:1000. Realign it to the host user
# so files Vite writes into the bind mount (node_modules, public/build) stay
# owned by the developer on the host. On macOS the host GID is often 20
# (staff), which Alpine already assigns to `dialout` — so reuse whatever group
# already holds that GID rather than trying to create a duplicate.
RUN set -eux; \
    if [ "${UID}:${GID}" != "1000:1000" ]; then \
        deluser node; \
        if ! getent group "${GID}" >/dev/null; then addgroup -g "${GID}" nodegrp; fi; \
        adduser -u "${UID}" -G "$(getent group "${GID}" | cut -d: -f1)" -s /bin/sh -D node; \
    fi

WORKDIR /var/www/html

USER node

EXPOSE 5173

# Host binding, origin and HMR settings live in vite.config.js.
CMD ["sh", "-c", "npm install && npm run dev"]

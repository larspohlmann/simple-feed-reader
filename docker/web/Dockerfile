# The production web container: stage one builds the Angular bundle, stage two
# serves it behind nginx with /api handed to php-fpm -- same-origin, so the
# prod build (apiBaseUrl '') needs no CORS. Replaces the old docker/frontend
# prod-preview image, which served the same topology over the DEV backend.
#
# Build context is the repo root (frontend/ and docker/web/ must both be
# reachable). TLS or plain HTTP is selected at CONTAINER START, not at build
# time -- see 10-select-mode.sh.
FROM node:22-slim AS build
WORKDIR /app
# Manifests first so the dependency layer caches across source-only changes.
COPY frontend/package.json frontend/package-lock.json ./
# node:22 ships npm 10.9.8, but the lockfile was authored by npm 11 (npm 10
# mis-resolves a transitive dep -- chokidar -- and rejects the lock). Pin npm 11.
RUN npm i -g npm@11 && npm ci --no-audit --no-fund
COPY frontend/ ./
# Default configuration is production (environment.ts -> apiBaseUrl '').
RUN npx ng build

FROM nginx:1.27-alpine
# Angular's application builder emits the browser bundle under dist/<project>/browser.
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
# Both server configs ship in the image; the entrypoint drop-in picks one.
COPY docker/web/tls.conf /etc/nginx/available/tls.conf
COPY docker/web/http.conf /etc/nginx/available/http.conf
COPY docker/web/10-select-mode.sh /docker-entrypoint.d/10-select-mode.sh

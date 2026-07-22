# Production-preview image for the SPA (compose "prod" profile only). Stage one
# builds the Angular app; stage two serves the static bundle behind nginx with
# /api proxied to Symfony's php-fpm — same-origin, so the prod build (apiBaseUrl
# '') needs no CORS. The default `docker compose up` uses the `frontend` service
# (Angular dev server) instead; this image is not built unless the profile is on.
#
# The build context is the repo root (see docker-compose.yml) so both frontend/
# and the shared nginx conf are reachable.
FROM node:22-slim AS build
WORKDIR /app
# Copy manifests first so the dependency layer caches across source-only changes.
COPY frontend/package.json frontend/package-lock.json ./
# node:22 ships npm 10.9.8, but the lockfile was authored by npm 11 (npm 10
# mis-resolves a transitive dep — chokidar — and rejects the lock). Pin npm 11.
RUN npm i -g npm@11 && npm ci --no-audit --no-fund
COPY frontend/ ./
# Default configuration is production (environment.ts → apiBaseUrl '').
RUN npx ng build

FROM nginx:1.27-alpine
# Angular's application builder emits the browser bundle under dist/<project>/browser.
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
COPY docker/frontend/prod.conf /etc/nginx/conf.d/default.conf

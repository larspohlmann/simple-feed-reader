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
# The release version the SPA reports, passed by scripts/prod-start.sh (empty
# on a plain `docker build`, which then keeps the committed 'dev' placeholder).
# Declared here, after npm ci, so a version change never busts the dependency
# layer -- but before `ng build`, which bakes version.ts into the bundle.
ARG APP_VERSION=
ARG APP_COMMIT=
ARG APP_BUILT_AT=
# One build helper, shared with the Strato release build, rewrites the
# committed version.ts placeholder in place (issue #500).
COPY scripts/stamp-version.sh /usr/local/bin/stamp-version.sh
RUN sh /usr/local/bin/stamp-version.sh apply \
      --version "$APP_VERSION" --commit "$APP_COMMIT" --built-at "$APP_BUILT_AT" \
      --version-ts src/environments/version.ts
# Default configuration is production (environment.ts -> apiBaseUrl '').
RUN npx ng build

FROM nginx:1.27-alpine
# Angular's application builder emits the browser bundle under dist/<project>/browser.
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
# Both server configs ship in the image; the entrypoint drop-in picks one.
COPY docker/web/tls.conf /etc/nginx/available/tls.conf
COPY docker/web/http.conf /etc/nginx/available/http.conf
COPY docker/web/10-select-mode.sh /docker-entrypoint.d/10-select-mode.sh
# nginx's entrypoint silently skips non-executable drop-ins, which would
# serve the stock welcome page instead of selecting HTTP/TLS mode.
RUN chmod +x /docker-entrypoint.d/10-select-mode.sh

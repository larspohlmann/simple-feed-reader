# Two stages, selected via compose `target:`.
#
#  * dev  -- the local development image: xdebug, dev-friendly limits, source
#            bind-mounted by docker-compose.yml (nothing is copied in).
#  * prod -- the production image: no xdebug, tuned opcache, dependencies and
#            source baked in. docker-compose.prod.yml builds this target.
#
# Both build from the REPO-ROOT context (the prod stage must COPY backend/);
# the root .dockerignore keeps vendor/, var/, node_modules/ and all TLS
# material out of the context.

FROM php:8.4-fpm-alpine AS dev

# install-php-extensions resolves each extension's build- and runtime-deps on
# alpine (icu for intl, libzip for zip, phpize toolchain for the xdebug pecl
# build) and cleans the build deps up afterwards.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache zip xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /app


FROM php:8.4-fpm-alpine AS prod

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# su-exec: the entrypoint fixes volume ownership as root, then drops to
# www-data for the cache warmup so runtime writes stay www-data-owned.
RUN install-php-extensions pdo_mysql intl opcache zip \
    && apk add --no-cache su-exec

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

# Manifests first so the dependency layer caches across source-only edits.
# --no-scripts: flex's cache:clear must not run at build time -- the real
# environment (DATABASE_URL, MAILER_DSN) only exists at run time, and the
# entrypoint warms the cache then anyway.
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# The release version /api/version reports, passed by scripts/prod-start.sh
# (empty on a plain `docker build`, which then leaves no version.json and the
# backend falls back to the development build). Declared after the source copy
# and autoloader dump so a version change never busts those layers. The same
# build helper the web image and the Strato build use writes version.json at
# the project root -- outside public/, so it is never web-served (issue #500).
ARG APP_VERSION=
ARG APP_COMMIT=
ARG APP_BUILT_AT=
COPY scripts/stamp-version.sh /usr/local/bin/stamp-version.sh
RUN sh /usr/local/bin/stamp-version.sh apply \
      --version "$APP_VERSION" --commit "$APP_COMMIT" --built-at "$APP_BUILT_AT" \
      --version-json version.json

# Real env vars beat the committed .env, so the baked APP_ENV=dev is inert.
ENV APP_ENV=prod APP_DEBUG=0

COPY docker/php/entrypoint-prod.sh /usr/local/bin/entrypoint-prod.sh
RUN chmod +x /usr/local/bin/entrypoint-prod.sh
ENTRYPOINT ["/usr/local/bin/entrypoint-prod.sh"]

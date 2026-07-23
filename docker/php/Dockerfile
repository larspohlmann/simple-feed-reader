# Dev image only. A future containerized production would add a multi-stage
# `prod` target here (composer install --no-dev, baked-in source, tuned opcache)
# — see the spec's "Deliberately out of scope" section.
FROM php:8.4-fpm-alpine

# install-php-extensions resolves each extension's build- and runtime-deps on
# alpine (icu for intl, libzip for zip, phpize toolchain for the xdebug pecl
# build) and cleans the build deps up afterwards.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache zip xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /app

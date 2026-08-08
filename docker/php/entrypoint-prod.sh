#!/bin/sh
# Prod entrypoint: prepare the mounted volumes, rebuild the Symfony cache for
# THIS image (the var/ volume can carry a previous release's cache, and a
# stale container class is a fatal error), then hand off to php-fpm.
#
# The cache pools (rate limiter, ALTCHA replay, OAuth state, login codes)
# live under var/cache-pools -- CACHE_DIRECTORY, set by docker-compose.prod.yml
# -- outside var/cache/prod, so the rebuild below never resets rate limits or
# replay protection.
#
# var/.ready is the readiness flag scripts/lib.sh polls before running console
# commands: removed first thing, written after the warmup succeeded.
set -e

if [ "$#" -gt 0 ]; then
  # Console/worker mode (#311). The php-fpm container owns the shared php-var
  # volume's lifecycle -- the var/.ready flag the scripts poll and the
  # var/cache/prod rebuild both belong to it alone, so this path must touch
  # neither: a worker restart that deleted them would break
  # wait_for_php_ready and flush a live FPM's cache. Wait for readiness,
  # drop to www-data, run the given command.
  until [ -f var/.ready ]; do sleep 2; done
  exec su-exec www-data "$@"
fi

rm -f var/.ready
mkdir -p var/cache var/log var/cache-pools config/jwt
chown -R www-data:www-data var config/jwt

rm -rf var/cache/prod
su-exec www-data php bin/console cache:warmup --no-interaction

touch var/.ready
chown www-data:www-data var/.ready

exec php-fpm

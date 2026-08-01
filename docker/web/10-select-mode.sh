#!/bin/sh
# Select the server config before nginx starts: TLS when certificates are
# mounted, plain HTTP otherwise. WEB_MODE=tls|http overrides the detection
# (auto is the default). An explicit tls without certs is a deliberate hard
# failure -- nginx will refuse to start -- rather than silently serving HTTP.
set -e

rm -f /etc/nginx/conf.d/default.conf

mode="${WEB_MODE:-auto}"
if [ "${mode}" = "auto" ]; then
    if [ -f /etc/nginx/certs/fullchain.pem ] && [ -f /etc/nginx/certs/privkey.pem ]; then
        mode=tls
    else
        mode=http
    fi
fi

cp "/etc/nginx/available/${mode}.conf" /etc/nginx/conf.d/default.conf
echo "simple-feed-reader web: ${mode} mode"

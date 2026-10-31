# The #1948 updater: Docker CLI + compose plugin and what update.sh needs on top.
FROM docker:27.5.1-cli
RUN apk add --no-cache bash git curl coreutils jq
COPY docker/updater/state.sh /usr/local/lib/sfr-updater/state.sh
COPY docker/updater/watch.sh /usr/local/bin/sfr-updater-watch
COPY docker/updater/run.sh /usr/local/bin/sfr-updater-run
ENTRYPOINT ["/usr/local/bin/sfr-updater-watch"]

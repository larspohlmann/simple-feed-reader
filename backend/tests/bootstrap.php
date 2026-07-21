<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Build schema once per process before DAMA transaction wrapping starts
passthru(sprintf(
    'php "%s/bin/console" doctrine:schema:drop --env=test --force --full-database --quiet',
    dirname(__DIR__),
), $dropExit);
passthru(sprintf(
    'php "%s/bin/console" doctrine:schema:create --env=test --quiet',
    dirname(__DIR__),
), $createExit);

if (0 !== $dropExit || 0 !== $createExit) {
    fwrite(STDERR, sprintf(
        "\nFailed to build test schema (drop exit=%d, create exit=%d); aborting before tests run.\n",
        $dropExit,
        $createExit,
    ));
    exit(1);
}

<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// The JWT keypair is gitignored, so CI and fresh checkouts have none. Tests
// need a real keypair (Lexik signs with RS256), so generate one on demand.
// The passphrase comes from .env — deliberately a throwaway in dev/test.
$jwtDir = dirname(__DIR__) . '/config/jwt';
if (!is_dir($jwtDir)) {
    mkdir($jwtDir, 0o777, true);
}
// Regenerate when *either* file is missing, and overwrite unconditionally: a
// half-present pair would otherwise make the command refuse to write and wedge
// the suite until config/jwt was cleared by hand.
if (!file_exists($jwtDir . '/private.pem') || !file_exists($jwtDir . '/public.pem')) {
    passthru(sprintf(
        'php "%s/bin/console" lexik:jwt:generate-keypair --env=test --overwrite --quiet',
        dirname(__DIR__),
    ), $keypairExit);

    if (0 !== $keypairExit) {
        fwrite(STDERR, "\nJWT keypair generation failed; aborting before tests run.\n");
        exit(1);
    }
}

// Rebuild the test database once per process, before DAMA transaction wrapping
// starts. Recreating the database (not just the schema) ensures a half-built
// database from an interrupted run cannot wedge the bootstrap. SQLite cannot
// list databases (which --if-exists needs), so its file is removed directly.
$rawDatabaseUrl = $_SERVER['DATABASE_URL'] ?? '';
$databaseUrl = is_string($rawDatabaseUrl) ? $rawDatabaseUrl : '';

if (str_starts_with($databaseUrl, 'sqlite:///')) {
    $path = str_replace(
        '%kernel.project_dir%',
        dirname(__DIR__),
        substr($databaseUrl, strlen('sqlite:///')),
    );
    if (file_exists($path)) {
        unlink($path);
    }
    $commands = ['doctrine:schema:create --env=test --quiet'];
} else {
    $commands = [
        'doctrine:database:drop --env=test --force --if-exists --quiet',
        'doctrine:database:create --env=test --quiet',
        'doctrine:schema:create --env=test --quiet',
    ];
}

foreach ($commands as $command) {
    passthru(sprintf('php "%s/bin/console" %s', dirname(__DIR__), $command), $exitCode);

    if (0 !== $exitCode) {
        fwrite(STDERR, sprintf(
            "\nTest database bootstrap failed (%s, exit=%d); aborting before tests run.\n",
            $command,
            $exitCode,
        ));
        exit(1);
    }
}

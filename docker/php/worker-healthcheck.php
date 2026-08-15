<?php

/**
 * Docker healthcheck for the #311 background worker.
 *
 * `docker compose ps` reports a container as healthy whenever its process is
 * up. That is the wrong signal here: the worker is a long-lived messenger
 * consumer, and a consumer that wedges inside a message keeps the process
 * alive while the sweep silently stops. The consumer touches
 * `worker_heartbeat` on every firing, work or not
 * (AdvanceRecommendationRunsHandler), so the row's age is the honest liveness
 * signal — the same one the poll driver arbitrates on.
 *
 * The staleness bound and the heartbeat's name are taken from the classes that
 * define them rather than copied: a duplicated 360 would silently disagree the
 * next time the provider timeout moves, and the failure mode of disagreeing is
 * a healthy worker reported dead in the middle of every long call. The name
 * comes from RecommendationDriverKind, which is what the consumer writes; it
 * lived on WorkerPresence when this file was written, and reading a constant
 * that had since moved made the healthcheck die on an undefined constant and
 * report every worker unhealthy.
 *
 * Only the autoloader is required for that — the class is never constructed,
 * so this stays what a healthcheck must be: no kernel, no container, no
 * database migrations, nothing that can fail for reasons unrelated to whether
 * the sweep is running.
 */

declare(strict_types=1);

use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;

// Absolute, not relative to __DIR__: the file is mounted into the image's bin
// directory, not into the project, so its own location says nothing about
// where the application lives. /app is the image's WORKDIR (docker/php/Dockerfile).
require_once '/app/vendor/autoload.php';

$databaseUrl = getenv('DATABASE_URL');

if (false === $databaseUrl || '' === $databaseUrl) {
    fwrite(STDERR, "DATABASE_URL is not set.\n");
    exit(1);
}

$parts = parse_url($databaseUrl);

if (false === $parts || !isset($parts['host'], $parts['path'])) {
    fwrite(STDERR, "DATABASE_URL is malformed.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s',
    $parts['host'],
    $parts['port'] ?? 3306,
    ltrim($parts['path'], '/'),
);

try {
    $connection = new PDO(
        $dsn,
        rawurldecode($parts['user'] ?? ''),
        rawurldecode($parts['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );

    $statement = $connection->prepare('SELECT touched_at FROM worker_heartbeat WHERE name = ?');
    $statement->execute([RecommendationDriverKind::PersistentWorker->value]);
    $touchedAt = $statement->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, 'Cannot read the heartbeat: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!is_string($touchedAt)) {
    fwrite(STDERR, "The worker has never touched its heartbeat.\n");
    exit(1);
}

// Datetimes are stored as naive UTC, so the timezone must be supplied here
// rather than inferred from the container's clock.
$ageInSeconds = time() - (int) strtotime($touchedAt . ' UTC');

if ($ageInSeconds > WorkerPresence::FRESH_SECONDS) {
    fwrite(STDERR, sprintf("The heartbeat is %d s old; the sweep has stopped.\n", $ageInSeconds));
    exit(1);
}

exit(0);

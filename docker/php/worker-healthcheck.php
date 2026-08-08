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
 * The staleness bound is WorkerPresence::FRESH_SECONDS. Keep the two in step:
 * one firing may spend a whole provider timeout (300 s) inside a single run,
 * so anything shorter reports a working worker as unhealthy in the middle of
 * every long call.
 *
 * Stack plumbing, deliberately outside backend/src: it holds no domain logic
 * and must run before (and independently of) the application booting.
 */

declare(strict_types=1);

const FRESH_SECONDS = 360;
const HEARTBEAT_NAME = 'recommendation-sweep';

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
    $statement->execute([HEARTBEAT_NAME]);
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

if ($ageInSeconds > FRESH_SECONDS) {
    fwrite(STDERR, sprintf("The heartbeat is %d s old; the sweep has stopped.\n", $ageInSeconds));
    exit(1);
}

exit(0);

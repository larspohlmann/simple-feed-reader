<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the last-import time into the web root so the browser can revalidate it
 * without touching PHP. A plain `{"lastUpdated": "..."}` JSON document on
 * purpose: anyone who inspects the poll's request sees exactly what it is, and
 * the timestamp changes every import so the tick can tell one from the next. It
 * is global — a per-user marker in a public directory would leak when a named
 * user reads their feeds; this leaks only "the reader last imported at this
 * time" (#720).
 *
 * A write failure is logged and swallowed. The marker is an optimisation, never
 * the source of truth — the poll keeps a floor that fetches regardless — so a
 * refresh must never fail because a static file could not be written.
 */
final readonly class ContentChangeMarker implements ContentChangeMarkerInterface
{
    public function __construct(
        private string $projectDir,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function markChanged(): void
    {
        $directory = $this->projectDir . '/public/state';
        if (!$this->ensureDirectory($directory)) {
            return;
        }
        $this->writeAtomically($directory, $directory . '/counts.json', $this->payload());
    }

    // UTC to the microsecond: the worker clock is not UTC on Strato, and two
    // imports can share a second. The microseconds also keep every write
    // distinct, which is the whole job of the marker.
    private function payload(): string
    {
        $lastUpdated = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        return json_encode(['lastUpdated' => $lastUpdated], \JSON_THROW_ON_ERROR);
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory)) {
            return true;
        }
        $this->logger->warning('Change marker: cannot create {directory}', ['directory' => $directory]);

        return false;
    }

    // Temp file plus rename on the same filesystem, so a polling reader never
    // sees half a write. The marker is chmod'd readable because the web server
    // serves it as a different user than the one the refresh runs as.
    private function writeAtomically(string $directory, string $target, string $token): void
    {
        $temp = @tempnam($directory, 'counts');
        if (false === $temp) {
            $this->logger->warning('Change marker: no temp file in {directory}', ['directory' => $directory]);

            return;
        }
        if (false !== @file_put_contents($temp, $token) && @chmod($temp, 0644) && @rename($temp, $target)) {
            return;
        }
        @unlink($temp);
        $this->logger->warning('Change marker: cannot write {target}', ['target' => $target]);
    }
}

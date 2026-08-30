<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Log\LoggerInterface;

/**
 * Writes one opaque, contentless token into the web root so the browser can
 * revalidate it without touching PHP. The token is global on purpose: a
 * per-user marker in a public directory would leak when a named user reads
 * their feeds; a single token leaks only "the reader imported something" (#720).
 *
 * A write failure is logged and swallowed. The marker is an optimisation, never
 * the source of truth — the poll keeps a floor that fetches regardless — so a
 * refresh must never fail because a static file could not be written.
 */
final readonly class ContentChangeMarker implements ContentChangeMarkerInterface
{
    public function __construct(
        private string $projectDir,
        private LoggerInterface $logger,
    ) {
    }

    public function markChanged(): void
    {
        $directory = $this->projectDir . '/public/state';
        if (!$this->ensureDirectory($directory)) {
            return;
        }
        $this->writeAtomically($directory, $directory . '/counts', bin2hex(random_bytes(8)));
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

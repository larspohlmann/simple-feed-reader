<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Reads a downloaded backup zip back apart in tests: the foundation member's
 * gzip bytes, and every entry part's gzip bytes in member-name order. The zip
 * stores its members uncompressed (BackupDownloadResponseFactory uses STORE),
 * so a member's raw bytes are exactly the gzip bytes the exporter produced.
 */
final readonly class BackupArchiveReader
{
    private string $zipPath;

    private function __construct(private \ZipArchive $archive)
    {
        $this->zipPath = $this->archive->filename;
    }

    public static function fromResponseContent(string $zipBytes): self
    {
        $path = tempnam(sys_get_temp_dir(), 'backup-archive-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file for the backup archive.');
        }
        file_put_contents($path, $zipBytes);

        $archive = new \ZipArchive();
        if (true !== $archive->open($path)) {
            throw new \RuntimeException('Could not open the downloaded backup as a zip archive.');
        }

        return new self($archive);
    }

    public function foundation(): string
    {
        return $this->memberBytes('000-foundation.ndjson.gz');
    }

    /**
     * @return list<string>
     */
    public function entryParts(): array
    {
        $names = [];
        for ($i = 0; $i < $this->archive->numFiles; ++$i) {
            $name = $this->archive->getNameIndex($i);
            if (\is_string($name) && '000-foundation.ndjson.gz' !== $name) {
                $names[] = $name;
            }
        }
        sort($names);

        return array_map($this->memberBytes(...), $names);
    }

    private function memberBytes(string $memberName): string
    {
        $bytes = $this->archive->getFromName($memberName);
        if (false === $bytes) {
            throw new \RuntimeException(sprintf('The backup archive has no member "%s".', $memberName));
        }

        return $bytes;
    }

    public function __destruct()
    {
        $this->archive->close();
        unlink($this->zipPath);
    }
}

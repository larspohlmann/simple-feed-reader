<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Exception\UnwritableFindingsFileException;
use App\Service\ReaderAudit\Model\AuditFindingModel;

final readonly class AuditFindingsFile
{
    /** @var resource */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public static function create(string $path): self
    {
        $directory = \dirname($path);
        if (!@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new UnwritableFindingsFileException(\sprintf('Cannot write %s.', $path));
        }

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new UnwritableFindingsFileException(\sprintf('Cannot write %s.', $path));
        }

        return new self($handle);
    }

    public function append(AuditFindingModel $finding): void
    {
        $line = json_encode($finding->toFindingsFileRecord(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        fwrite($this->handle, $line . "\n");
    }

    public function close(): void
    {
        fclose($this->handle);
    }
}

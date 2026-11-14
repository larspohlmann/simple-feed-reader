<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki\Exception;

final class CorruptSpoolFileException extends \RuntimeException
{
    public function __construct(string $file, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('The Loki spool file %s is unreadable or not a JSON batch.', $file), 0, $previous);
    }
}

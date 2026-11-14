<?php

declare(strict_types=1);

namespace App\Service\Operations\Logging\Loki\LokiSink;

interface LokiSinkInterface
{
    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    public function write(array $lines): void;
}

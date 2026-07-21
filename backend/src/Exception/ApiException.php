<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Base for every error the API reports deliberately. Anything that is NOT an
 * ApiException is a bug, and the listener turns it into an opaque 500 — so
 * never extend this to paper over an unexpected failure.
 */
abstract class ApiException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public readonly string $type,
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($detail ?? $title);
    }
}

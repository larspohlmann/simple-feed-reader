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
     * $previous is accepted purely so subclasses can keep the underlying cause
     * attached for the log. It is never read when building the problem
     * document — see ApiExceptionListener, which reads only the five public
     * properties above — so chaining a driver or HTTP-client exception here
     * cannot leak its message to a client.
     *
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public readonly string $type,
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail ?? $title, 0, $previous);
    }
}

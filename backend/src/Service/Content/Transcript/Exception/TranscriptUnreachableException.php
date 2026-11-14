<?php

declare(strict_types=1);

namespace App\Service\Transcript\Exception;

/** The transcript host failed for now; the row is retried after a backoff. */
final class TranscriptUnreachableException extends \RuntimeException
{
    public static function from(string $url, \Throwable $exception): self
    {
        $message = \sprintf('The transcript at %s is unreachable: %s', $url, $exception->getMessage());

        return new self($message, 0, $exception);
    }

    public static function answered(string $url, int $status): self
    {
        return new self(\sprintf('The transcript at %s answered %d; retried later.', $url, $status));
    }
}

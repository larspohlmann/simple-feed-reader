<?php

declare(strict_types=1);

namespace App\Service\Transcript\Exception;

/** The transcript will never be usable: refused, missing, too large or unreadable. Its row is failed for good. */
final class TranscriptUnusableException extends \RuntimeException
{
    public static function empty(): self
    {
        return new self('The transcript holds no text.');
    }

    public static function malformed(\JsonException $exception): self
    {
        return new self('The transcript is not valid JSON.', 0, $exception);
    }

    public static function refused(string $url, \Throwable $exception): self
    {
        $message = \sprintf('The transcript at %s was refused: %s', $url, $exception->getMessage());

        return new self($message, 0, $exception);
    }

    public static function answered(string $url, int $status): self
    {
        return new self(\sprintf('The transcript at %s answered %d.', $url, $status));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint did not answer, or answered something that is not a model list.
 * Separate from CredentialsRejectedException because the two need different
 * advice: check the address, versus check the key.
 */
final class ProviderUnreachableException extends \RuntimeException
{
    public static function didNotAnswer(\Throwable $transportFailure): self
    {
        return new self('That address did not answer.', 0, $transportFailure);
    }

    public static function answeredWithStatus(int $status, ?string $reason = null): self
    {
        return new self(
            null === $reason
                ? sprintf('That provider answered with status %d.', $status)
                : sprintf('That provider answered with status %d: %s', $status, $reason),
        );
    }

    public static function answeredMoreThan(int $maximumBytes): self
    {
        return new self(sprintf('That provider answered with more than %d bytes.', $maximumBytes));
    }
}

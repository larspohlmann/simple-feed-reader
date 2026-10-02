<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** The endpoint answered, and refused the key. */
final class CredentialsRejectedException extends \RuntimeException
{
    public static function refusedKey(): self
    {
        return new self('That provider refused the API key.');
    }
}

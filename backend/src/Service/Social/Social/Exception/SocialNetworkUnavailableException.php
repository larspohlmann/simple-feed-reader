<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialNetworkUnavailableException extends SocialServiceFailureException
{
    public static function naming(string $serviceName, int $status): self
    {
        return new self(
            $serviceName,
            $status,
            sprintf("%s isn't responding right now — it's probably overloaded. Try again in a minute.", $serviceName),
        );
    }
}

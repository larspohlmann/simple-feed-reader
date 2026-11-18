<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialNetworkUnavailableException extends SocialNetworkUnreachableException
{
    private function __construct(public readonly string $serviceName, int $status)
    {
        parent::__construct(
            sprintf("%s isn't responding right now — it's probably overloaded. Try again in a minute.", $serviceName),
            previous: new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status)),
        );
    }

    public static function naming(string $serviceName, int $status): self
    {
        return new self($serviceName, $status);
    }
}

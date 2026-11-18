<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialNetworkUnavailableException extends SocialNetworkUnreachableException
{
    private function __construct(public readonly string $instance)
    {
        parent::__construct(sprintf(
            "%s isn't responding right now — it's probably overloaded. Try again in a minute.",
            $instance,
        ));
    }

    public static function fromHost(string $instance): self
    {
        return new self($instance);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialWriteOutcomeUnknownException extends SocialNetworkUnreachableException
{
    private function __construct(public readonly string $instance)
    {
        parent::__construct(sprintf(
            '%s had trouble with this request, so it may or may not have gone through. '
            . 'Check before trying again.',
            $instance,
        ));
    }

    public static function fromHost(string $instance): self
    {
        return new self($instance);
    }
}

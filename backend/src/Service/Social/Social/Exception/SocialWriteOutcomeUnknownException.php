<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialWriteOutcomeUnknownException extends SocialNetworkUnreachableException
{
    private function __construct(public readonly string $serviceName, public readonly int $status)
    {
        parent::__construct(
            sprintf(
                '%s had trouble with this request, so it may or may not have gone through. Check before trying again.',
                $serviceName,
            ),
            previous: new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status)),
        );
    }

    public static function naming(string $serviceName, int $status): self
    {
        return new self($serviceName, $status);
    }
}

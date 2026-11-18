<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class SocialWriteOutcomeUnknownException extends SocialServiceFailureException
{
    public static function naming(string $serviceName, int $status): self
    {
        return new self(
            $serviceName,
            $status,
            sprintf(
                '%s had trouble with this request, so it may or may not have gone through. Check before trying again.',
                $serviceName,
            ),
        );
    }
}

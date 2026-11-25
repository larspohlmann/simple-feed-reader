<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

use App\Service\Outbound\Fetch\Exception\FetchException;

final class SocialWriteOutcomeUnknownException extends SocialServiceFailureException
{
    public static function naming(string $serviceName, int $status): self
    {
        return new self(
            $serviceName,
            self::formatMessage($serviceName),
            new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status)),
        );
    }

    public static function unanswered(string $serviceName, FetchException $previous): self
    {
        return new self($serviceName, self::formatMessage($serviceName), $previous);
    }

    private static function formatMessage(string $serviceName): string
    {
        return sprintf(
            '%s had trouble with this request, so it may or may not have gone through. Check before trying again.',
            $serviceName,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

abstract class SocialServiceFailureException extends SocialNetworkUnreachableException
{
    final protected function __construct(public readonly string $serviceName, string $message, \Throwable $previous)
    {
        parent::__construct($message, previous: $previous);
    }

    final protected static function buildAnsweredCause(string $serviceName, int $status): \RuntimeException
    {
        return new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status));
    }
}

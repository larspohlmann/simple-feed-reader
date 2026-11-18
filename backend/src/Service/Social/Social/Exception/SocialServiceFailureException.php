<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

abstract class SocialServiceFailureException extends SocialNetworkUnreachableException
{
    final protected function __construct(public readonly string $serviceName, int $status, string $message)
    {
        parent::__construct(
            $message,
            previous: new \RuntimeException(sprintf('%s answered %d.', $serviceName, $status)),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The provider answered that the request itself is wrong. Apart from ProviderUnreachableException because
 * resending the same request earns the same answer: the run ends on the first one.
 */
final class ProviderRejectedRequestException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

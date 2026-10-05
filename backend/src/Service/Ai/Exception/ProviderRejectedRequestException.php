<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** Resending the same request earns the same answer, so unlike ProviderUnreachableException it spends no strike. */
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

<?php

declare(strict_types=1);

namespace App\Service\Logging;

use App\DependencyInjection\ProcessLifetimeState;
use Symfony\Component\Uid\Ulid;

#[ProcessLifetimeState('RequestIdListener starts a new id for every request and every worker message')]
final class RequestIdProvider
{
    private ?string $requestId = null;

    public function current(): string
    {
        return $this->requestId ??= (string) new Ulid();
    }

    public function startNew(): string
    {
        return $this->requestId = (string) new Ulid();
    }

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Symfony\Component\Uid\Ulid;

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

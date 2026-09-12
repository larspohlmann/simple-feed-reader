<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Logging\Loki\LokiEndpoint;

final class StubLokiEndpoint implements LokiEndpoint
{
    public function __construct(
        private ?string $pushUrl = 'http://loki:3100/loki/api/v1/push',
        private ?string $username = null,
        private ?string $token = null,
    ) {
    }

    public function pushUrl(): ?string
    {
        return $this->pushUrl;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function token(): ?string
    {
        return $this->token;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class EnvLokiEndpoint implements LokiEndpoint
{
    public function __construct(
        private string $pushUrl,
        private string $username,
        private string $token,
    ) {
    }

    public function pushUrl(): ?string
    {
        return '' === $this->pushUrl ? null : $this->pushUrl;
    }

    public function username(): ?string
    {
        return '' === $this->username ? null : $this->username;
    }

    public function token(): ?string
    {
        return '' === $this->token ? null : $this->token;
    }
}

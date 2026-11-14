<?php

declare(strict_types=1);

namespace App\Service\Operations\Logging\Loki\LokiEndpoint;

interface LokiEndpointInterface
{
    public function pushUrl(): ?string;

    public function username(): ?string;

    public function token(): ?string;
}

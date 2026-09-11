<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

interface LokiEndpoint
{
    public function pushUrl(): ?string;

    public function username(): ?string;

    public function token(): ?string;
}

<?php

declare(strict_types=1);

namespace App\Service\Profiling\PyroscopeEndpoint;

interface PyroscopeEndpointInterface
{
    public function pushUrl(): ?string;
}

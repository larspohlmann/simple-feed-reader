<?php

declare(strict_types=1);

namespace App\Service\Settings\ServingHost;

interface ServingHostInterface
{
    public function get(): string;
}

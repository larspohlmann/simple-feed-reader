<?php

declare(strict_types=1);

namespace App\Service\Settings;

interface ServingHost
{
    public function get(): string;
}

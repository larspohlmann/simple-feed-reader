<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

enum LokiDelivery
{
    case Direct;
    case Spool;
}

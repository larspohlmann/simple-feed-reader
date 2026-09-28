<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki\Model;

enum LokiDelivery
{
    case Direct;
    case Spool;
}

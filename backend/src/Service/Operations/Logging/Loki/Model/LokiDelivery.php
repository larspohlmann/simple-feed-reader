<?php

declare(strict_types=1);

namespace App\Service\Operations\Logging\Loki\Model;

enum LokiDelivery
{
    case Direct;
    case Spool;
}

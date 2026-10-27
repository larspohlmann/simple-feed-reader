<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestAdjustment: string
{
    case TokenParameter = 'token-parameter';
    case OutputCap = 'output-cap';
    case ReasoningSuppression = 'reasoning-suppression';
}

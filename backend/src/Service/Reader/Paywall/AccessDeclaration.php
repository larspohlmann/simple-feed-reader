<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

enum AccessDeclaration
{
    case Paywalled;
    case Free;
    case Undeclared;
}

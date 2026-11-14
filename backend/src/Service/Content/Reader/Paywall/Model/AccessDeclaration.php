<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Paywall\Model;

enum AccessDeclaration
{
    case Paywalled;
    case Free;
    case Undeclared;
}

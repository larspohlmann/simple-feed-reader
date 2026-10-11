<?php

declare(strict_types=1);

namespace App\Enum;

enum ShareEnrichmentKind: string
{
    case AppView = 'appview';
    case LinkCards = 'link_cards';
}

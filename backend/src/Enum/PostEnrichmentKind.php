<?php

declare(strict_types=1);

namespace App\Enum;

enum PostEnrichmentKind: string
{
    case AppView = 'appview';
    case LinkCards = 'link_cards';
}

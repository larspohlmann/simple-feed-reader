<?php

declare(strict_types=1);

namespace App\Service\Reading;

enum ReadScopeKind: string
{
    case All = 'all';
    case Feed = 'feed';
    case Tag = 'tag';
}

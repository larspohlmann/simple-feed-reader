<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

enum ReadScopeKind: string
{
    case All = 'all';
    case Feed = 'feed';
    case Tag = 'tag';
}

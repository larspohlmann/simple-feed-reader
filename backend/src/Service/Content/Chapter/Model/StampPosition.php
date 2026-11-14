<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\Model;

enum StampPosition
{
    case Leading;
    case Trailing;
    case Inner;
}

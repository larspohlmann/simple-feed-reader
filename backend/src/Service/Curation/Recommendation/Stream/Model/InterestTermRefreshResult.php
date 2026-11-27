<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

enum InterestTermRefreshResult
{
    case Stored;
    case Retrying;
    case Failed;
    case Skipped;
}

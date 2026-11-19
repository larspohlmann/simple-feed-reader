<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Model;

enum HiddenProfileMoveOutcome
{
    case Skipped;
    case Started;
    case Waiting;
    case Finished;
}

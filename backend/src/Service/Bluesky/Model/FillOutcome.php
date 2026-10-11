<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

enum FillOutcome
{
    case Unfilled;
    case Filled;
    case AwaitingLinkCards;
}

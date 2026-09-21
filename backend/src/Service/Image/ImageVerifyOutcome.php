<?php

declare(strict_types=1);

namespace App\Service\Image;

enum ImageVerifyOutcome
{
    case Measured;
    case Kept;
    case Dropped;
    case Retried;
}

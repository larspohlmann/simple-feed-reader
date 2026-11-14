<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

enum ImageVerifyOutcome
{
    case Measured;
    case Kept;
    case Dropped;
    case Retried;
}

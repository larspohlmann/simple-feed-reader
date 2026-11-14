<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Discovery\Model;

/** Whether discovery may offer a plain HTML page as a scraped source. */
enum ScrapeFallback
{
    case Enabled;
    case Disabled;
}

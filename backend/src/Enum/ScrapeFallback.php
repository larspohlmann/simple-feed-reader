<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether discovery may offer a plain HTML page as a scraped source. An enum
 * rather than a bool so the decision reads at the call site, and so discovery
 * never has to know which user it is deciding for.
 */
enum ScrapeFallback
{
    case Enabled;
    case Disabled;
}

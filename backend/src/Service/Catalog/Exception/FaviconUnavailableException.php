<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

/**
 * No usable icon could be fetched for a catalog row. The caller records
 * faviconFailedAt and moves on — a missing icon degrades to the monogram, so
 * this is never fatal to anything.
 */
final class FaviconUnavailableException extends \RuntimeException
{
}

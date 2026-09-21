<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

/** The host answered, but access control or this fetcher's own type/size policy
 *  stopped the download; a browser may still render the resource. */
final class FaviconRejectedException extends FaviconUnavailableException
{
}

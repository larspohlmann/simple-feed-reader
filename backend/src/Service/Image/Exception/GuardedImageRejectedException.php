<?php

declare(strict_types=1);

namespace App\Service\Image\Exception;

/** The host answered, but access control or this downloader's own type/size policy
 *  stopped the download; a browser may still render the resource. */
final class GuardedImageRejectedException extends GuardedImageUnavailableException
{
}

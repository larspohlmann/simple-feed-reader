<?php

declare(strict_types=1);

namespace App\Service\Image\Exception;

/** The host answered 401 or 403: access control, which a first visit's cookies may satisfy. */
final class ImageRefusedException extends ImageUnavailableException
{
}

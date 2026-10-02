<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/** The chosen connection is not ready or cannot distil a profile itself, so it cannot build one for another engine. */
final class ProfileConnectionRejectedException extends \RuntimeException
{
}

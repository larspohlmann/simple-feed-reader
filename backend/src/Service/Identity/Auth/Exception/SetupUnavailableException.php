<?php

declare(strict_types=1);

namespace App\Service\Identity\Auth\Exception;

/** No setup secret is configured, or an administrator exists; both look alike, so neither is revealed. */
final class SetupUnavailableException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Refused by PasskeySignInAvailability::guard(): sign-in is off, or the relying-party id does not fit the host. */
final class PasskeySignInDisabledException extends \RuntimeException implements PasskeySignInFailure
{
}

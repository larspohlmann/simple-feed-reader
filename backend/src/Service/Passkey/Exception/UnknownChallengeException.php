<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The challenge handle is not redeemable: never issued, already redeemed, or expired. One case on purpose, so a
 * caller cannot probe for live handles.
 */
final class UnknownChallengeException extends \RuntimeException implements PasskeySignInFailure
{
}

<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** Refused by PasskeyRemovalPolicy: removing this passkey would leave the account no way to sign in. */
final class LastSignInMethodException extends \RuntimeException
{
}

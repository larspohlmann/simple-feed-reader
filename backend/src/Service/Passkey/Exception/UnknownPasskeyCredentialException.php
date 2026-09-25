<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The assertion named a credential id no account holds. Its own type so the client can prune the dead browser
 * entry (#727); it accepts the same oracle DuplicatePasskeyException does.
 */
final class UnknownPasskeyCredentialException extends \RuntimeException implements PasskeySignInFailure
{
}

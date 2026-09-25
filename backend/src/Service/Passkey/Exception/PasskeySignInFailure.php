<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * A reason AssertionVerifier::verify() refuses a login. PasskeyAuthenticator catches this marker and turns each
 * into a plain AuthenticationException, so every passkey login failure goes through LoginFailureHandler.
 */
interface PasskeySignInFailure extends \Throwable
{
}

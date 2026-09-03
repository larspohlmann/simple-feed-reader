<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * Refused by PasskeySignInAvailability::guard(): the instance-wide
 * `passkeySignInEnabled` toggle is off, or the configured relying-party id is
 * not valid for the public base URL's host.
 *
 * Thrown from every enforcement point that gates a passkey endpoint. On
 * PasskeyController-routed paths it surfaces as-is, `application/problem+json`,
 * through ApiExceptionListener like any other ApiException. On the login path
 * it's caught by PasskeyAuthenticator's own `catch (ApiException)` block,
 * exactly like AssertionRejectedException, and rewritten into a plain
 * AuthenticationException — so a disabled instance fails a passkey login
 * cleanly, a 401 through LoginFailureHandler, never a 500.
 */
final class PasskeySignInDisabledException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'passkey_sign_in_disabled',
            403,
            'Passkey sign-in is disabled',
            'This instance has turned off passkey sign-in.',
        );
    }
}

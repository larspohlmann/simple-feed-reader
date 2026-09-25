<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeySignInProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AssertionRejectedException => new ResolvedProblem(new ApiProblem(
                'passkey_assertion_rejected',
                'Passkey login rejected',
                Response::HTTP_UNAUTHORIZED,
                'The passkey could not be verified.',
            )),
            $exception instanceof UnknownPasskeyCredentialException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_credential',
                'Unknown passkey',
                Response::HTTP_UNAUTHORIZED,
                'This passkey is not registered here.',
            )),
            $exception instanceof PasskeySignInDisabledException => new ResolvedProblem(new ApiProblem(
                'passkey_sign_in_disabled',
                'Passkey sign-in is disabled',
                Response::HTTP_FORBIDDEN,
                'This instance has turned off passkey sign-in.',
            )),
            default => null,
        };
    }
}

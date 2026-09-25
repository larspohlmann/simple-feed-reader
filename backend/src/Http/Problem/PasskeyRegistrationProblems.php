<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use App\Service\Passkey\Exception\UnknownChallengeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeyRegistrationProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AttestationRejectedException => new ResolvedProblem(new ApiProblem(
                'passkey_attestation_rejected',
                'Passkey registration rejected',
                Response::HTTP_BAD_REQUEST,
                'The passkey could not be verified.',
            )),
            $exception instanceof DuplicatePasskeyException => new ResolvedProblem(new ApiProblem(
                'passkey_already_registered',
                'Passkey already registered',
                Response::HTTP_CONFLICT,
                'This passkey is already registered.',
            )),
            $exception instanceof PasskeyChallengeOwnershipException => new ResolvedProblem(new ApiProblem(
                'passkey_challenge_owner_mismatch',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This registration challenge was not issued to you.',
            )),
            $exception instanceof UnknownChallengeException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_challenge',
                'Unknown or expired passkey challenge',
                Response::HTTP_BAD_REQUEST,
            )),
            $exception instanceof PasskeyNotFoundException => new ResolvedProblem(new ApiProblem(
                'passkey_not_found',
                'No such passkey',
                Response::HTTP_NOT_FOUND,
            )),
            $exception instanceof LastSignInMethodException => new ResolvedProblem(new ApiProblem(
                'passkey_last_sign_in_method',
                'Cannot remove your last sign-in method',
                Response::HTTP_CONFLICT,
                'This is your only way to sign in. Set a password or link a sign-in provider first.',
            )),
            default => null,
        };
    }
}

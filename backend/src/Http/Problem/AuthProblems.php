<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\InvalidCredentialsException;
use App\Service\Auth\Exception\AccountNotActiveException;
use App\Service\Auth\Exception\InvalidSetupSecretException;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\Auth\Exception\SetupUnavailableException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidCredentialsException => new ResolvedProblem(new ApiProblem(
                'invalid_credentials',
                'Invalid credentials',
                Response::HTTP_UNAUTHORIZED,
                'Email address or password is incorrect.',
            )),
            $exception instanceof InvalidTokenException => new ResolvedProblem(new ApiProblem(
                'invalid_token',
                'Invalid token',
                Response::HTTP_BAD_REQUEST,
                'This link is invalid, already used, or expired.',
            )),
            $exception instanceof InvalidSetupSecretException => new ResolvedProblem(new ApiProblem(
                'invalid_setup_secret',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'The setup secret is incorrect.',
            )),
            $exception instanceof SetupUnavailableException => new ResolvedProblem(new ApiProblem(
                'setup_unavailable',
                'Not found',
                Response::HTTP_NOT_FOUND,
                'Setup is not available.',
            )),
            $exception instanceof AccountNotActiveException => new ResolvedProblem(
                new ApiProblem(
                    'account_not_active',
                    'Account not active',
                    Response::HTTP_FORBIDDEN,
                    $exception->getMessage(),
                ),
                extensions: ['accountStatus' => $exception->accountStatus],
            ),
            default => null,
        };
    }
}

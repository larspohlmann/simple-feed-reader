<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Exception\UnknownProviderException;
use Symfony\Component\HttpFoundation\Response;

final readonly class OAuthProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownProviderException => new ResolvedProblem(new ApiProblem(
                'unknown_provider',
                'Unknown sign-in provider',
                Response::HTTP_NOT_FOUND,
                'That sign-in provider is not available.',
            )),
            $exception instanceof OAuthFailedException => new ResolvedProblem(new ApiProblem(
                'oauth_failed',
                'Sign-in failed',
                Response::HTTP_BAD_GATEWAY,
                'Signing in with that provider did not work. Please try again.',
            )),
            default => null,
        };
    }
}

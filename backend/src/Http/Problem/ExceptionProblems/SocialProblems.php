<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Exception\SocialAccountAlreadyConnectedException;
use App\Service\Social\Exception\SocialAccountMismatchException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\TooManySocialAccountsException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SocialProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof SocialAccountNotFoundException => new ResolvedProblem(new ApiProblem(
                'social_account_not_found',
                'Social account not found',
                Response::HTTP_NOT_FOUND,
                'No such social account for this account.',
            )),
            $exception instanceof TooManySocialAccountsException => new ResolvedProblem(new ApiProblem(
                'social_account_limit',
                'Too many social accounts',
                Response::HTTP_CONFLICT,
                'This account already holds the maximum number of social accounts.',
            )),
            $exception instanceof SocialAccountAlreadyConnectedException => new ResolvedProblem(new ApiProblem(
                'social_account_already_connected',
                'Account already connected',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            self::isCredentialRefusal($exception) => new ResolvedProblem(new ApiProblem(
                'social_credentials_rejected',
                'The network refused these credentials',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            self::isUnresolvable($exception) => new ResolvedProblem(new ApiProblem(
                'social_account_unresolvable',
                'Account not found on the network',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof SocialNetworkUnreachableException => new ResolvedProblem(new ApiProblem(
                'social_network_unreachable',
                'The network could not be reached',
                Response::HTTP_BAD_GATEWAY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }

    private static function isCredentialRefusal(\Throwable $exception): bool
    {
        return $exception instanceof SocialCredentialsRejectedException
            || $exception instanceof SocialAccountMismatchException;
    }

    private static function isUnresolvable(\Throwable $exception): bool
    {
        return $exception instanceof SocialAccountUnresolvableException
            || $exception instanceof InvalidInstanceUrlException;
    }
}

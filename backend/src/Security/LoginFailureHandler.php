<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\AccountNotActiveException;
use App\Exception\InvalidCredentialsException;
use App\Exception\RateLimitedException;
use App\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * The firewall short-circuits before kernel.exception, so login failures need
 * their own translation into the problem+json contract. Bad password and
 * unknown email deliberately produce the identical response - distinguishing
 * them would turn the endpoint into an account-enumeration oracle.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $apiException = match (true) {
            $exception instanceof AccountStatusException
                => new AccountNotActiveException($exception->accountStatus),
            $exception instanceof TooManyLoginAttemptsAuthenticationException
                => new RateLimitedException(900),
            default => new InvalidCredentialsException(),
        };

        $problem = new ApiProblem(
            $apiException->type,
            $apiException->title,
            $apiException->status,
            $apiException->detail,
        );

        $payload = $problem->toArray();
        $headers = ['Content-Type' => 'application/problem+json'];

        if ($apiException instanceof AccountNotActiveException) {
            $payload['accountStatus'] = $apiException->accountStatus;
        }

        if ($apiException instanceof RateLimitedException) {
            $headers['Retry-After'] = (string) $apiException->retryAfterSeconds;
        }

        return new JsonResponse($payload, $problem->status, $headers);
    }
}

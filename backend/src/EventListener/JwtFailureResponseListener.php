<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\ApiProblem;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes Lexik's JWT rejections speak problem+json like the rest of the API.
 *
 * ApiExceptionListener cannot do this. Lexik builds its own response — from
 * JWTAuthenticator::start() for a missing token, from onAuthenticationFailure()
 * for an invalid, expired or non-active one — via ExceptionEvent::setResponse(),
 * which calls stopPropagation() and ends the kernel.exception chain before
 * ApiExceptionListener runs. The onAuthenticationFailure case doesn't even
 * reach kernel.exception: the authenticator responds during kernel.request.
 * Hooking Lexik's own events sidesteps both instead of fighting priorities.
 *
 * Every branch answers the same opaque 401. In particular a suspended user's
 * otherwise-valid token must NOT report the account status: whoever presents it
 * may have stolen it and doesn't need to know why it stopped working. Account
 * status is disclosed only at login, where the password has just been verified.
 */
#[AsEventListener(event: Events::JWT_NOT_FOUND, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_INVALID, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_EXPIRED, method: 'onJwtFailure')]
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE, method: 'onJwtFailure')]
final class JwtFailureResponseListener
{
    public function onJwtFailure(AuthenticationFailureEvent $event): void
    {
        $problem = new ApiProblem(
            'unauthorized',
            'Unauthorized',
            Response::HTTP_UNAUTHORIZED,
            'Authentication is required to access this resource.',
        );

        $event->setResponse(new JsonResponse(
            $problem->toArray(),
            $problem->status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }
}

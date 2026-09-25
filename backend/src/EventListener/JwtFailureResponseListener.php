<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lexik answers JWT failures itself and stops kernel.exception, so its own events are hooked here. Every branch
 * is the opaque 401: whoever presents a suspended account's token may have stolen it, and learns nothing.
 */
#[AsEventListener(event: Events::JWT_NOT_FOUND, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_INVALID, method: 'onJwtFailure')]
#[AsEventListener(event: Events::JWT_EXPIRED, method: 'onJwtFailure')]
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE, method: 'onJwtFailure')]
final readonly class JwtFailureResponseListener
{
    public function __construct(
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onJwtFailure(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->responses->create($this->problems->resolve($event->getException(), '/api')));
    }
}

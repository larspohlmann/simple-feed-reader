<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Problem\Factory\ProblemResponseFactory;
use App\Http\Problem\ProblemCatalog;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Renders every exception under /api or /maintenance as problem+json; controllers never build an error by hand.
 * After the firewall (1) turns an anonymous denial into 401; before ErrorListener logs (0, registered after this):
 * ProblemCatalog logs what is unexpected, and a mapped 4xx is no error.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responseFactory,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/maintenance')) {
            return;
        }

        $event->setResponse($this->responseFactory->create($this->problems->resolve($event->getThrowable(), $path)));
    }
}

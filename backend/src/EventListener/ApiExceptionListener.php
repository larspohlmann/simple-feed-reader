<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/** Renders every exception under /api or /maintenance as problem+json; controllers never build an error by hand. */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/maintenance')) {
            return;
        }

        $event->setResponse($this->responses->create($this->problems->resolve($event->getThrowable(), $path)));
    }
}

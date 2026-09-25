<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApiExceptionListenerTest extends TestCase
{
    private function event(string $path, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function listener(): ApiExceptionListener
    {
        return new ApiExceptionListener(
            new ProblemCatalog([], new NullLogger(), debug: false),
            new ProblemResponseFactory(),
        );
    }

    /** @return array<mixed> */
    private function payloadOf(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testIgnoresNonApiPaths(): void
    {
        $event = $this->event('/some/page', new NotFoundHttpException());
        $this->listener()->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    /** /maintenance is outside the firewall, but its errors must still be problem+json, not Symfony's HTML page. */
    public function testMaintenancePathsAreAlsoHandled(): void
    {
        $event = $this->event('/maintenance/refresh', new \LogicException('boom'));
        $this->listener()->onKernelException($event);

        self::assertNotNull($event->getResponse());
    }

    /**
     * setResponse() stops propagation, so a listener that answers first ends the chain. That is why Lexik's 401
     * is normalised by JwtFailureResponseListener on Lexik's own events, not here.
     */
    public function testAnEarlierListenersResponseEndsTheChain(): void
    {
        $dispatcher = new EventDispatcher();

        $firstResponse = new Response('first', 418);
        $dispatcher->addListener(
            ExceptionEvent::class,
            static fn (ExceptionEvent $event) => $event->setResponse($firstResponse),
            priority: 1,
        );
        $dispatcher->addListener(
            ExceptionEvent::class,
            $this->listener()->onKernelException(...),
            priority: 0,
        );

        $event = $this->event('/api/thing', new NotFoundHttpException());
        $dispatcher->dispatch($event, ExceptionEvent::class);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(
            $firstResponse,
            $event->getResponse(),
            'ApiExceptionListener must never have run: setResponse() stopped propagation before it.',
        );
    }

    public function testTheListenerRunsWhenNoEarlierListenerAnswered(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ExceptionEvent::class, $this->listener()->onKernelException(...));

        $event = $this->event('/api/thing', new NotFoundHttpException());
        $dispatcher->dispatch($event, ExceptionEvent::class);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->payloadOf($response)['type']);
    }
}

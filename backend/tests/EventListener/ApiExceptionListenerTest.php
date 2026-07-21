<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Exception\RateLimitedException;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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
        return new ApiExceptionListener(new NullLogger(), debug: false);
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

    public function testRendersApiExceptionAsProblemJson(): void
    {
        $event = $this->event('/api/auth/login', new ValidationException(['email' => ['Bad.']]));
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame([
            'type' => 'validation_error',
            'title' => 'Validation failed',
            'status' => 422,
            'detail' => 'One or more fields are invalid.',
            'errors' => ['email' => ['Bad.']],
        ], $this->payloadOf($response));
    }

    public function testRateLimitedCarriesRetryAfterHeader(): void
    {
        $event = $this->event('/api/auth/login', new RateLimitedException(30));
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testUnwrapsMapRequestPayloadValidationFailures(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Not a valid email address.', null, [], null, 'email', 'nope'),
            new ConstraintViolation('Too short.', null, [], null, 'password', 'x'),
        ]);
        $wrapped = new UnprocessableEntityHttpException(
            'Validation failed',
            new ValidationFailedException(null, $violations),
        );

        $event = $this->event('/api/auth/register', $wrapped);
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'email' => ['Not a valid email address.'],
            'password' => ['Too short.'],
        ], $this->payloadOf($response)['errors']);
    }

    public function testMapsPlainHttpExceptions(): void
    {
        $event = $this->event('/api/subscriptions/9', new NotFoundHttpException());
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->payloadOf($response)['type']);
    }

    public function testUnexpectedExceptionsBecomeOpaque500(): void
    {
        $event = $this->event('/api/entries', new \LogicException('DB password is hunter2'));
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());

        $payload = $this->payloadOf($response);
        self::assertSame('internal_error', $payload['type']);
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
        self::assertArrayNotHasKey('detail', $payload);
    }

    /**
     * A pass-through header must never be able to downgrade the content type:
     * the union operator `+` keeps the LEFT value on collision, which would
     * silently serve the problem document as text/html. Other headers the
     * exception carries (WWW-Authenticate on a firewall 401) must survive.
     */
    public function testExceptionHeadersCannotOverrideTheProblemContentType(): void
    {
        $exception = new HttpException(401, 'Nope', null, [
            'Content-Type' => 'text/html',
            'WWW-Authenticate' => 'Bearer',
        ]);

        $event = $this->event('/api/me', $exception);
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', $this->payloadOf($response)['type']);
    }

    /**
     * The firewall raises Security\Core\Exception\AuthenticationException, which
     * does NOT implement HttpExceptionInterface — without explicit handling it
     * would fall through to the opaque 500 branch. Task 8 needs anonymous
     * GET /api/me to answer 401.
     */
    public function testAuthenticationExceptionBecomes401(): void
    {
        $event = $this->event('/api/me', new AuthenticationException('Not authenticated.'));
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $this->payloadOf($response)['type']);
    }

    /**
     * Subclasses (BadCredentialsException, InsufficientAuthenticationException)
     * must map the same way, since the firewall raises those in practice.
     */
    public function testAuthenticationExceptionSubclassesAlsoBecome401(): void
    {
        $event = $this->event('/api/me', new BadCredentialsException());
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $this->payloadOf($response)['type']);
    }

    /**
     * Security\Core\Exception\AccessDeniedException — distinct from
     * HttpKernel's AccessDeniedHttpException, which already flows through the
     * HttpExceptionInterface branch. Task 14 needs 403 for a non-admin.
     */
    public function testAccessDeniedExceptionBecomes403(): void
    {
        $event = $this->event('/api/admin/users', new AccessDeniedException());
        $this->listener()->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->payloadOf($response)['type']);
    }

    /**
     * /maintenance is outside the firewall and hand-authenticates, but its
     * errors must still be problem+json rather than Symfony's HTML page.
     */
    public function testMaintenancePathsAreAlsoHandled(): void
    {
        $event = $this->event('/maintenance/refresh', new \LogicException('boom'));
        $this->listener()->onKernelException($event);

        self::assertNotNull($event->getResponse());
    }

    /**
     * Settles a contradiction this file used to contain.
     *
     * The docblock on testMaintenancePathsAreAlsoHandled claimed the firewall
     * "sets its own response without stopping propagation", that we
     * "intentionally overwrite it", and that adding an early-return guard for
     * an already-set response would make the test fail. ApiExceptionListener's
     * own comment claimed the exact opposite. Both could not be true, and
     * neither was pinned: that test builds an event whose response is null and
     * never calls setResponse(), so it exercised none of it — an early-return
     * guard could be added to the production listener with all tests still
     * green.
     *
     * The listener's comment is the correct one. ExceptionEvent extends
     * RequestEvent, whose setResponse() calls stopPropagation() unconditionally
     * (see Symfony\Component\HttpKernel\Event\RequestEvent). So a listener that
     * answers first ENDS the chain, and ApiExceptionListener cannot overwrite
     * anything — which is precisely why Lexik's 401 has to be normalised by
     * App\EventListener\JwtFailureResponseListener hooking Lexik's own events
     * instead of by fighting listener priorities here.
     *
     * Calling the listener directly, as every other test in this file does,
     * could never show this: the short-circuit is the DISPATCHER's behaviour,
     * not the listener's. So this one goes through a real EventDispatcher with
     * a real higher-priority listener, which is the only place the guarantee
     * actually lives.
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

    /**
     * The complement, and the case that actually ships: when nothing has
     * answered, the chain reaches this listener and it does the work.
     */
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

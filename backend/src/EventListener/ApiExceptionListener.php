<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\AccountNotActiveException;
use App\Exception\ApiException;
use App\Exception\RateLimitedException;
use App\Http\ApiProblem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Turns every exception raised under /api or /maintenance into an RFC 7807
 * document. Controllers must never build an error response by hand.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/maintenance')) {
            return;
        }

        $exception = $event->getThrowable();
        $headers = [];

        /**
         * RFC 7807 extension members — keys added ALONGSIDE the standard
         * problem fields. Kept separate from ApiProblem, which models the
         * standard document and nothing else.
         *
         * @var array<string, mixed>
         */
        $extensions = [];

        if ($exception instanceof ApiException) {
            $problem = new ApiProblem(
                $exception->type,
                $exception->title,
                $exception->status,
                $exception->detail,
                $exception->errors,
            );

            if ($exception instanceof RateLimitedException) {
                $headers['Retry-After'] = (string) $exception->retryAfterSeconds;
            }

            if ($exception instanceof AccountNotActiveException) {
                // The client shows a different message per status, so the
                // status travels in the payload rather than only in $detail's
                // prose. LoginFailureHandler adds the identical key for the
                // password login — it cannot use this listener, because the
                // firewall short-circuits before kernel.exception — so the two
                // sign-in paths report a blocked account in one shape.
                $extensions['accountStatus'] = $exception->accountStatus;
            }
        } elseif ($exception instanceof HttpExceptionInterface) {
            $problem = $this->fromHttpException($exception);
            $headers = $exception->getHeaders();
        } elseif ($exception instanceof AuthenticationException) {
            // The firewall's own exceptions do NOT implement
            // HttpExceptionInterface, so without these two branches they would
            // fall through to the opaque 500 below — turning "not logged in"
            // into "server broken". Covers subclasses such as
            // BadCredentialsException and InsufficientAuthenticationException.
            //
            // Note what this branch does NOT do: it cannot rewrite a response
            // the firewall has already produced. The firewall's ExceptionListener
            // runs at priority 1, ahead of this listener's 0, and sets its
            // response with ExceptionEvent::setResponse(). ExceptionEvent
            // extends RequestEvent, whose setResponse() calls stopPropagation()
            // unconditionally, so once the firewall answers, this listener is
            // never reached. That is why Lexik's {"code":401,"message":"..."}
            // shape is normalised by App\EventListener\JwtFailureResponseListener,
            // which hooks Lexik's own events instead — see that class. This
            // branch still matters for authentication exceptions that reach
            // kernel.exception with no response set.
            //
            // Verified rather than assumed: ApiExceptionListenerTest::
            // testAnEarlierListenersResponseEndsTheChain dispatches through a
            // real EventDispatcher and asserts this listener never runs. The
            // test file used to claim the opposite — that we deliberately
            // overwrite an already-set response — while asserting neither.
            // A corollary of the real behaviour: an early-return guard here for
            // an already-set response would be unreachable dead code, not a
            // behaviour change. There is nothing to guard against.
            $problem = new ApiProblem(
                'unauthorized',
                'Unauthorized',
                Response::HTTP_UNAUTHORIZED,
                'Authentication is required to access this resource.',
            );
        } elseif ($exception instanceof AccessDeniedException) {
            // Security\Core\Exception\AccessDeniedException — distinct from
            // HttpKernel's AccessDeniedHttpException, which the
            // HttpExceptionInterface branch above already covers.
            $problem = new ApiProblem(
                'forbidden',
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'You do not have permission to access this resource.',
            );
        } else {
            // Unexpected: the message may contain connection strings, tokens or
            // row data, so it goes to the log and never to the client.
            $this->logger->error('Unhandled API exception', [
                'exception' => $exception,
                'path' => $path,
            ]);

            $problem = new ApiProblem(
                'internal_error',
                'Internal server error',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->debug ? $exception->getMessage() : null,
            );
        }

        // array_merge, not `+`: on a key collision the union operator keeps the
        // LEFT value, so an HttpException carrying its own Content-Type would
        // silently downgrade the problem document to text/html. Our header must
        // win, while pass-through headers (WWW-Authenticate, Retry-After)
        // survive. Placing ours last also beats case-variant keys, because
        // HeaderBag lowercases names and the later entry wins.
        $event->setResponse(new JsonResponse(
            array_merge($problem->toArray(), $extensions),
            $problem->status,
            array_merge($headers, ['Content-Type' => 'application/problem+json']),
        ));
    }

    private function fromHttpException(HttpExceptionInterface $exception): ApiProblem
    {
        // HttpExceptionInterface extends \Throwable, so getPrevious() is always
        // available — no instanceof guard needed.
        $previous = $exception->getPrevious();

        // #[MapRequestPayload] reports constraint failures by wrapping a
        // ValidationFailedException in a 422 HttpException. Unwrap it so the
        // client gets per-field messages instead of one opaque string.
        if ($previous instanceof ValidationFailedException) {
            $errors = [];
            foreach ($previous->getViolations() as $violation) {
                $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
            }

            return new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                $errors,
            );
        }

        $status = $exception->getStatusCode();

        return new ApiProblem(
            match ($status) {
                Response::HTTP_UNAUTHORIZED => 'unauthorized',
                Response::HTTP_FORBIDDEN => 'forbidden',
                Response::HTTP_NOT_FOUND => 'not_found',
                Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
                Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
                default => $status >= 500 ? 'internal_error' : 'request_error',
            },
            Response::$statusTexts[$status] ?? 'Error',
            $status,
        );
    }
}

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
    public function __construct(
        private readonly LoginTimingEqualizer $timingEqualizer,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Before building the response, not after: the whole point is that the
        // client cannot time the difference between a missing user and a wrong
        // password. AuthenticatorManager returns this response only once the
        // handler has returned, so the delay lands inside the measured window.
        $this->timingEqualizer->equalize($exception, $this->submittedIdentifier($request));

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

    /**
     * The address this request tried to log in as, for the timing equalizer.
     *
     * Read straight from the request body rather than from the exception: the
     * exception's token is not populated for every failure mode (a not-found
     * user arrives masked, a throttled request never reached an authenticator
     * at all), and this handler must behave identically for all of them.
     *
     * `email` is the key because security.yaml's json_login block declares
     * `username_path: email`. The body is still readable here: HttpFoundation
     * buffers the raw content on first read, and JsonLoginAuthenticator has
     * already read it to find these very fields, so php://input being
     * single-shot cannot bite.
     *
     * json_decode rather than Request::toArray(), because toArray() THROWS on
     * an empty or non-array body — a Symfony JsonException, not the \\JsonException
     * one might reasonably catch — and an uncaught throw here would turn a 401
     * into a 500 on the endpoint whose entire contract is that every failure
     * looks the same. In practice JsonLoginAuthenticator rejects those bodies
     * with a 400 long before this handler runs (verified for an empty body, a
     * bare scalar and a missing password), so the throw would be unreachable
     * today; making the reader total means it stays unreachable if that
     * ordering ever changes.
     *
     * Not normalised on the way out. UserRepository::findOneByEmail() does that
     * itself, which is the same seam the user provider went through, so the
     * lookup this feeds cannot disagree with the one that just failed.
     */
    private function submittedIdentifier(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return null;
        }

        $email = $payload['email'] ?? null;

        return \is_string($email) && '' !== $email ? $email : null;
    }
}

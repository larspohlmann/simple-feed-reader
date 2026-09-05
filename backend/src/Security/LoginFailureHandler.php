<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\AccountNotActiveException;
use App\Exception\InvalidCredentialsException;
use App\Exception\RateLimitedException;
use App\Http\ApiProblem;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
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
 *
 * One deliberate exception since #727: an unknown passkey credential id keeps
 * its own type so the browser can prune the dead entry — see
 * UnknownPasskeyCredentialException. The timing equalizer still runs first.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private LoginTimingEqualizer $timingEqualizer,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Before building the response, not after: the whole point is that the
        // client cannot time the difference between a missing user and a wrong
        // password. AuthenticatorManager returns this response only once the
        // handler has returned, so the delay lands inside the measured window.
        $this->timingEqualizer->equalize($exception, $this->submittedIdentifier($request));

        $previous = $exception->getPrevious();
        $apiException = match (true) {
            $previous instanceof UnknownPasskeyCredentialException => $previous,
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
     * Read from the request body, not the exception: the exception's token isn't
     * populated for every failure mode (masked not-found user, throttled request
     * never reaching an authenticator), and this handler must treat them alike.
     *
     * `email` is the key because json_login declares `username_path: email`. The
     * body is still readable here: JsonLoginAuthenticator already read it for
     * these fields, so php://input being single-shot cannot bite.
     *
     * json_decode, not Request::toArray() — toArray() THROWS on an empty or
     * non-array body, and an uncaught throw here would turn a 401 into a 500 on
     * an endpoint whose contract is that every failure looks the same.
     * JsonLoginAuthenticator rejects such bodies with a 400 first, so the throw
     * is unreachable today; a total reader keeps it that way if that changes.
     *
     * Not normalised on the way out: UserRepository::findOneByEmail() does that
     * itself, so the lookup this feeds cannot disagree with the one that failed.
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

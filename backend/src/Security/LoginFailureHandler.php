<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\AccountNotActiveException;
use App\Exception\InvalidCredentialsException;
use App\Exception\RateLimitedException;
use App\Http\Problem\ProblemCatalog;
use App\Http\Problem\ProblemResponseFactory;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * The firewall answers before kernel.exception, so login failures resolve here. A bad password and an unknown
 * email give one response (no enumeration oracle); only an unknown passkey keeps its own type (#727).
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private LoginTimingEqualizer $timingEqualizer,
        private ProblemCatalog $problems,
        private ProblemResponseFactory $responses,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Before building the response: the delay must land inside the window the client measures.
        $this->timingEqualizer->equalize($exception, $this->submittedIdentifier($request));

        return $this->responses->create(
            $this->problems->resolve(self::domainFailure($exception), $request->getPathInfo()),
        );
    }

    private static function domainFailure(AuthenticationException $exception): \Throwable
    {
        $previous = $exception->getPrevious();

        return match (true) {
            $previous instanceof UnknownPasskeyCredentialException => $previous,
            $exception instanceof AccountStatusException => new AccountNotActiveException($exception->accountStatus),
            $exception instanceof TooManyLoginAttemptsAuthenticationException
                => new RateLimitedException(self::lockoutSeconds($exception)),
            default => new InvalidCredentialsException(),
        };
    }

    /** LoginThrottlingListener reports the remaining lockout in whole minutes; a manual throw may carry none. */
    private static function lockoutSeconds(TooManyLoginAttemptsAuthenticationException $exception): int
    {
        $minutes = $exception->getMessageData()['%minutes%'];

        return \is_int($minutes) && $minutes > 0 ? $minutes * self::SECONDS_PER_MINUTE : self::SECONDS_PER_MINUTE;
    }

    /**
     * Read from the body, not the exception: the token is not populated for every failure mode. json_decode,
     * not Request::toArray(), which throws on a non-array body and would turn this 401 into a 500.
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

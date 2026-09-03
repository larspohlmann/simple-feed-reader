<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\ApiException;
use App\Service\Passkey\AssertionVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a WebAuthn login ("assertion") response as its own firewall
 * (#624) — config/packages/security.yaml's `passkey_login` block, inserted
 * between `login` and `api` because firewalls match in declaration order and
 * `api` already matches every `^/api` path.
 *
 * A firewall, not a controller, makes "the JWT a passkey login returns is the
 * same JWT password login returns" structural rather than copied. $successHandler
 * is the exact `lexik_jwt_authentication.handler.authentication_success` service
 * `json_login` uses, so both flows call the identical `JWTTokenManager::create()`
 * and pick up every JWT-issuance listener — StampLastLoginOnTokenIssue among
 * them. $failureHandler is the same `App\Security\LoginFailureHandler`.
 *
 * Reusing LoginFailureHandler means LoginTimingEqualizer runs on every passkey
 * login failure too, with an empty submitted identifier (this flow carries no
 * e-mail), giving every failure the same constant delay regardless of which check
 * in AssertionVerifier rejected it. That leaks nothing: a discoverable-credential
 * login has no address to enumerate.
 *
 * Verification runs lazily, inside the UserBadge's user loader, not eagerly in
 * authenticate(): Symfony's LoginThrottlingListener::checkPassport runs on the
 * same CheckPassportEvent at higher priority than the listener that resolves the
 * badge, so it can reject an over-budget request with a 429 before
 * AssertionVerifier ever runs. Calling verify() eagerly would skip that.
 *
 * UserBadge is given a fixed, non-secret sentinel (THROTTLE_IDENTIFIER) rather
 * than an empty string: a discoverable login carries no identifier to key
 * throttling on, and UserBadge's constructor deprecates an empty one.
 * DefaultLoginRateLimiter keys on `identifier-IP`, so a fixed identifier collapses
 * to one bucket per client IP.
 *
 * `final class`, not `final readonly class`: PHP refuses a readonly class that
 * extends a non-readonly parent, and AbstractAuthenticator is not one.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    private const string LOGIN_PATH = '/api/auth/passkey/login';

    /**
     * Not a real identifier — see the class docblock for why this is a
     * fixed sentinel rather than the empty string or anything client-supplied.
     */
    private const string THROTTLE_IDENTIFIER = 'passkey';

    public function __construct(
        private readonly AssertionVerifier $verifier,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
        private readonly LoginFailureHandler $failureHandler,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::LOGIN_PATH === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $payload = self::decodedPayload($request);

        return new SelfValidatingPassport(
            new UserBadge(self::THROTTLE_IDENTIFIER, fn (): User => $this->verifiedUser($payload)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }

    /**
     * Runs lazily from the UserBadge loader — see the class docblock.
     * AssertionVerifier's typed rejections are never allowed to reach the
     * kernel from here: they are always translated into a plain
     * AuthenticationException so LoginFailureHandler (and, upstream of it,
     * the login_throttling listener) handle every passkey login failure
     * exactly like a password one.
     *
     * @param array<string, mixed> $payload
     */
    private function verifiedUser(array $payload): User
    {
        $handle = $payload['handle'] ?? null;
        $credential = $payload['credential'] ?? null;

        (\is_string($handle) && \is_array($credential))
            || throw new AuthenticationException('Malformed passkey login request.');

        try {
            /** @var array<string, mixed> $credential */
            return $this->verifier->verify($handle, $credential)->getUser();
        } catch (ApiException $exception) {
            throw new AuthenticationException('Passkey assertion rejected.', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}

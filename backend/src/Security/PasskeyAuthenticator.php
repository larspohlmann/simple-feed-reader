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
 * Authenticates a WebAuthn login ("assertion") response as its OWN firewall
 * (#624) — config/packages/security.yaml's `passkey_login` block, inserted
 * BETWEEN `login` and `api` because firewalls match in declaration order and
 * `api` already matches every `^/api` path; a block placed after it would
 * never be reached.
 *
 * A FIREWALL, DELIBERATELY, NOT A CONTROLLER: the point is that "the JWT a
 * passkey login returns is the same JWT password login returns" is
 * structural rather than copied. $successHandler is the exact
 * `lexik_jwt_authentication.handler.authentication_success` service
 * `json_login` uses, injected directly rather than re-implemented, so both
 * flows call the identical `JWTTokenManager::create()` on the resolved user
 * and pick up every listener already wired to JWT issuance —
 * StampLastLoginOnTokenIssue among them — for free. $failureHandler is the
 * same App\Security\LoginFailureHandler password login uses, for the same
 * reason: one place decides what a login failure's response looks like.
 *
 * Reusing LoginFailureHandler means LoginTimingEqualizer runs on every
 * passkey login failure too, but with an EMPTY submitted identifier — this
 * flow's request body carries no e-mail for it to read. That gives every
 * failure the SAME constant delay regardless of which check inside
 * AssertionVerifier rejected it. That leaks nothing: a discoverable-credential
 * login has no address to enumerate in the first place. And it costs the
 * success path nothing, since the equalizer only ever runs from
 * onAuthenticationFailure().
 *
 * VERIFICATION IS DELIBERATELY LAZY — done inside the UserBadge's user
 * loader, not called eagerly here in authenticate(). authenticate() only
 * builds the Passport; nothing invokes UserBadge::getUser() until
 * createToken() does, several steps later in AuthenticatorManager's own
 * pipeline — and login_throttling's LoginThrottlingListener runs on
 * CheckPassportEvent, which fires BEFORE that point. Calling
 * AssertionVerifier::verify() eagerly here would make every rejected
 * assertion throw before CheckPassportEvent ever gets dispatched, so the
 * throttle's own pre-emptive check would never get a chance to reject a
 * sixth attempt with a 429 — it would keep calling the verifier and keep
 * answering 401, forever. Deferring the actual verification into the
 * lazily-invoked loader is what lets the throttle listener's priority
 * (2080, checked first) actually gate it.
 *
 * The user identifier passed to UserBadge is deliberately the empty string:
 * a discoverable-credential login carries no e-mail or username to key
 * throttling on, so DefaultLoginRateLimiter's per-identifier bucket
 * collapses to a single one shared by every request from one IP — which is
 * the budget this firewall's login_throttling config is sized for.
 *
 * `final class`, not `final readonly class` — PHP refuses a readonly class
 * that extends a non-readonly parent, and AbstractAuthenticator is not one.
 * The constructor-promoted properties below are still individually
 * readonly, which is as close to the house style as that constraint allows.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    private const string LOGIN_PATH = '/api/auth/passkey/login';

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

        return new SelfValidatingPassport(new UserBadge('', fn (): User => $this->verifiedUser($payload)));
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

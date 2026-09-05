<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Service\Passkey\AssertionOptionsFactory;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\PasskeyListing;
use App\Service\Passkey\PasskeyRemoval;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Service\Passkey\RegistrationOptionsFactory;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The passkey enrolment, login-options, listing and removal endpoints (#624).
 * Every route except `loginOptions()` requires a bearer token — see the
 * access_control comment in config/packages/security.yaml for why the others
 * need an explicit rule despite the otherwise-public `^/api/auth/` prefix, and
 * why `loginOptions()` is left out: a discoverable-credential login has no
 * account to authenticate as until the assertion comes back.
 *
 * `$availability->guard()` gates every action EXCEPT `delete()` and the
 * unreachable `login()` stub (#624 follow-up): when the instance-wide switch
 * is off, or the configured relying party is invalid for the public base URL,
 * registering, listing and requesting a login challenge all refuse with 403.
 * DELETE stays reachable on purpose — a user with a credential they can no
 * longer use must still be able to remove it, at no sign-in risk. The login
 * path has no controller action to guard; see AssertionVerifier::verify()
 * for the equivalent enforcement.
 *
 * A narrowing (review, fix round 1): DELETE only helps a user with a SECOND
 * sign-in method. Every route here needs a bearer token, and a passkey-only
 * account — no password, no linked OAuth — has no way to obtain one while
 * sign-in is disabled, since AssertionVerifier's guard refuses the login that
 * would mint it. That account is stuck until an admin re-enables the toggle.
 * The exemption still stands — it costs nothing — it just doesn't reach
 * everyone who might want it.
 *
 * REVIEWER NOTE, before adding a seventh passkey endpoint: the guard is
 * written out FIVE times — `registerOptions()`, `register()`, `list()`,
 * `loginOptions()` here, plus `AssertionVerifier::verify()` — with `delete()`
 * deliberately left out. A shared "every passkey action" wrapper would either
 * special-case DELETE or silently start guarding it, so the repetition is
 * intentional; but nothing enforces the list, so a new endpoint that forgets
 * `$availability->guard()` (or `AssertionVerifier`'s) fails open, not closed.
 * Check this docblock's count against the endpoint table in
 * `docs/superpowers/specs/2026-08-29-624-passkey-login-design.md` §4.2 when
 * adding one.
 */
final readonly class PasskeyController
{
    public function __construct(
        private RegistrationOptionsFactory $registrationOptionsFactory,
        private AssertionOptionsFactory $assertionOptionsFactory,
        private AttestationVerifier $attestationVerifier,
        private PasskeyListing $listing,
        private PasskeyRemoval $removal,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $passkeyChallengeLimiter,
        private PasskeySignInAvailability $availability,
    ) {
    }

    #[Route('/api/auth/passkey/register/options', name: 'api_auth_passkey_register_options', methods: ['POST'])]
    public function registerOptions(#[CurrentUser] User $user): JsonResponse
    {
        $this->availability->guard();

        return new JsonResponse($this->registrationOptionsFactory->create($user));
    }

    /**
     * Anonymous on purpose — see the class docblock. Rate-limited on its own
     * budget (`passkey_challenge` in rate_limiter.yaml) because conditional
     * mediation calls this on every login-page view, from every visitor, and
     * each call writes a cache entry.
     *
     * The limiter runs BEFORE the availability guard, deliberately (fix
     * round 1): the reverse order would let an anonymous caller hammer this
     * endpoint — which still touches the database every call — for free on a
     * disabled instance, since the 403 would fire before any budget was
     * charged. Charging the limiter first means a disabled instance still
     * 429s an attacker who exceeds the budget, exactly like an enabled one.
     */
    #[Route('/api/auth/passkey/login/options', name: 'api_auth_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $this->rateLimitGuard->enforceForClient($this->passkeyChallengeLimiter, $request);
        $this->availability->guard();

        return new JsonResponse($this->assertionOptionsFactory->create());
    }

    /**
     * Never executed: PasskeyAuthenticator's own firewall intercepts the
     * request and its injected success/failure handlers write the response
     * — see that class's docblock. The route exists only so the firewall's
     * pattern resolves to a real one: RouterListener runs before the
     * firewall (priority 32 vs 8), so with no route here a POST would 404
     * before PasskeyAuthenticator ever saw it — same reasoning as
     * AuthController::login() for the password equivalent.
     */
    #[Route('/api/auth/passkey/login', name: 'api_auth_passkey_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Handled by PasskeyAuthenticator.');
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/auth/passkey/register', name: 'api_auth_passkey_register', methods: ['POST'])]
    public function register(
        #[CurrentUser] User $user,
        #[MapRequestPayload] RegisterPasskeyRequest $request,
    ): JsonResponse {
        $this->availability->guard();
        $this->attestationVerifier->verifyAndStore($user, $request);

        return new JsonResponse($this->listing->forUser($user), Response::HTTP_CREATED);
    }

    #[Route('/api/auth/passkeys', name: 'api_auth_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $this->availability->guard();

        return new JsonResponse($this->listing->forUser($user));
    }

    /** Own credential 204; a foreign or unknown id 404 — see PasskeyRemoval. */
    #[Route(
        '/api/auth/passkeys/{id}',
        name: 'api_auth_passkeys_delete',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->removal->remove($user, $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

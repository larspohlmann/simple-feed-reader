<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Http\PasskeyJson;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AssertionOptionsFactory;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\PasskeyRemovalPolicy;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Service\Passkey\RegistrationOptionsFactory;
use App\Service\RateLimit\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The passkey enrolment, login-options, listing and removal endpoints
 * (#624). Every route except `loginOptions()` requires a bearer token — see
 * the access_control comment in config/packages/security.yaml for why the
 * others need an explicit rule despite living under the otherwise-public
 * `^/api/auth/` prefix, and for why `loginOptions()` is deliberately left
 * out of that rule: a discoverable-credential login has no account to
 * authenticate as until the assertion comes back.
 *
 * `$availability->guard()` gates every action here EXCEPT `delete()` and the
 * unreachable `login()` stub (#624 follow-up): when the instance-wide
 * passkey-sign-in switch is off, or the configured relying party is not
 * valid for the public base URL, registering, listing and requesting a login
 * challenge all refuse with a 403. DELETE stays reachable on purpose — a
 * user with a credential they can no longer use must still be able to remove
 * it, and doing so carries no sign-in risk. The login path itself has no
 * controller action to guard; see AssertionVerifier::verify() for the
 * equivalent enforcement on that side.
 */
final readonly class PasskeyController
{
    public function __construct(
        private RegistrationOptionsFactory $registrationOptionsFactory,
        private AssertionOptionsFactory $assertionOptionsFactory,
        private AttestationVerifier $attestationVerifier,
        private UserPasskeyRepository $passkeys,
        private PasskeyRemovalPolicy $removalPolicy,
        private EntityManagerInterface $em,
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
     */
    #[Route('/api/auth/passkey/login/options', name: 'api_auth_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $this->availability->guard();
        $this->rateLimitGuard->enforceForClient($this->passkeyChallengeLimiter, $request);

        return new JsonResponse($this->assertionOptionsFactory->create());
    }

    /**
     * Never executed: PasskeyAuthenticator's own firewall intercepts the
     * request and its injected success/failure handlers write the response
     * — see that class's docblock. The route exists purely so the firewall's
     * pattern resolves to a real one: RouterListener runs before the
     * firewall (priority 32 vs 8), so with no route here a POST would 404
     * before PasskeyAuthenticator ever saw it — the same reasoning
     * AuthController::login() documents for the password equivalent.
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

        return new JsonResponse(
            PasskeyJson::passkeys($this->passkeys->findForUser($user)),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/auth/passkeys', name: 'api_auth_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $this->availability->guard();

        return new JsonResponse(PasskeyJson::passkeys($this->passkeys->findForUser($user)));
    }

    /**
     * Looks the credential up by `(id, user)` in one query — never a
     * fetch-by-id followed by an owner comparison, which is exactly the shape
     * that would let a 403 confirm another account's credential id exists. A
     * foreign id therefore comes back 404, indistinguishable from one that was
     * never registered at all.
     */
    #[Route(
        '/api/auth/passkeys/{id}',
        name: 'api_auth_passkeys_delete',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $passkey = $this->passkeys->findOneForUser($user, $id) ?? throw new NotFoundHttpException('No such passkey.');

        $this->removalPolicy->guardRemoval($user, $passkey);

        $this->em->remove($passkey);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

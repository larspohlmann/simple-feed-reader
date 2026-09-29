<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Http\PasskeyJson;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\Factory\AssertionOptionsFactory;
use App\Service\Passkey\Factory\RegistrationOptionsFactory;
use App\Service\Passkey\PasskeyListing;
use App\Service\Passkey\PasskeyRemoval;
use App\Service\Passkey\PasskeySignInAvailability;
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
 * Every action but delete() calls $availability->guard() (the login is guarded in AssertionVerifier::verify()); one
 * that forgets it fails open. delete() stays open so a user can remove a credential they can no longer use.
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
     * Anonymous, on its own `passkey_challenge` budget: every login-page view calls it and each call writes a cache
     * entry. The limiter runs before the guard, so a disabled instance still 429s instead of refusing for free.
     */
    #[Route('/api/auth/passkey/login/options', name: 'api_auth_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        $this->rateLimitGuard->enforceForClient($this->passkeyChallengeLimiter, $request->getClientIp());
        $this->availability->guard();

        return new JsonResponse($this->assertionOptionsFactory->create());
    }

    /** Never executed: PasskeyAuthenticator's firewall answers. The route keeps RouterListener from 404ing first. */
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
        $this->attestationVerifier->verifyAndStore($user, $request->toAttestation());

        return new JsonResponse(PasskeyJson::listing($this->listing->forUser($user)), Response::HTTP_CREATED);
    }

    #[Route('/api/auth/passkeys', name: 'api_auth_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $this->availability->guard();

        return new JsonResponse(PasskeyJson::listing($this->listing->forUser($user)));
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

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Http\PasskeyJson;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\RegistrationOptionsFactory;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The passkey enrolment endpoints (#624): issuing registration options and
 * verifying the completed ceremony, with listing and removal following in a
 * later task. Every route on this controller requires a bearer token — see
 * the access_control comment in config/packages/security.yaml for why that
 * needs an explicit rule despite living under the otherwise-public
 * `^/api/auth/` prefix.
 */
final readonly class PasskeyController
{
    public function __construct(
        private RegistrationOptionsFactory $registrationOptionsFactory,
        private AttestationVerifier $attestationVerifier,
        private UserPasskeyRepository $passkeys,
    ) {
    }

    #[Route('/api/auth/passkey/register/options', name: 'api_auth_passkey_register_options', methods: ['POST'])]
    public function registerOptions(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(PasskeyJson::registrationOptions($this->registrationOptionsFactory->create($user)));
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/auth/passkey/register', name: 'api_auth_passkey_register', methods: ['POST'])]
    public function register(
        #[CurrentUser] User $user,
        #[MapRequestPayload] RegisterPasskeyRequest $request,
    ): JsonResponse {
        $this->attestationVerifier->verifyAndStore($user, $request);

        return new JsonResponse(
            PasskeyJson::passkeys($this->passkeys->findForUser($user)),
            Response::HTTP_CREATED,
        );
    }
}

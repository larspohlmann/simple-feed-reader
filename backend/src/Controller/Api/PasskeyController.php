<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\PasskeyJson;
use App\Service\Passkey\RegistrationOptionsFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The passkey enrolment endpoints (#624): issuing registration options here,
 * with verification, listing and removal following in later tasks. Every
 * route on this controller requires a bearer token — see the access_control
 * comment in config/packages/security.yaml for why that needs an explicit
 * rule despite living under the otherwise-public `^/api/auth/` prefix.
 */
final readonly class PasskeyController
{
    public function __construct(private RegistrationOptionsFactory $registrationOptionsFactory)
    {
    }

    #[Route('/api/auth/passkey/register/options', name: 'api_auth_passkey_register_options', methods: ['POST'])]
    public function registerOptions(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(PasskeyJson::registrationOptions($this->registrationOptionsFactory->create($user)));
    }
}

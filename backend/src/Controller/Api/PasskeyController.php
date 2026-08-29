<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Http\PasskeyJson;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\PasskeyRemovalPolicy;
use App\Service\Passkey\RegistrationOptionsFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The passkey enrolment, listing and removal endpoints (#624). Every route on
 * this controller requires a bearer token — see the access_control comment in
 * config/packages/security.yaml for why that needs an explicit rule despite
 * living under the otherwise-public `^/api/auth/` prefix.
 */
final readonly class PasskeyController
{
    public function __construct(
        private RegistrationOptionsFactory $registrationOptionsFactory,
        private AttestationVerifier $attestationVerifier,
        private UserPasskeyRepository $passkeys,
        private PasskeyRemovalPolicy $removalPolicy,
        private EntityManagerInterface $em,
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

    #[Route('/api/auth/passkeys', name: 'api_auth_passkeys_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
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

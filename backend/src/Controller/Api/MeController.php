<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateLocaleRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The client's view of its own account. Deliberately hand-built rather than
 * serialised from the entity, so a column added later (a password hash, a
 * token, an internal flag) cannot leak into the response by default.
 */
final readonly class MeController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->profile($user));
    }

    /**
     * The account's language. The server is the source of truth: the SPA caches
     * it per device, but this is what AccountMailer reads to pick the language
     * of every transactional email, and what a native client has to read
     * because it cannot see browser storage.
     */
    #[Route('/api/me', name: 'api_me_update_locale', methods: ['PATCH'])]
    public function updateLocale(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateLocaleRequest $request,
    ): JsonResponse {
        $user->setLocale($request->locale);
        $this->entityManager->flush();

        return new JsonResponse($this->profile($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus()->value,
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'locale' => $user->getLocale(),
        ];
    }
}

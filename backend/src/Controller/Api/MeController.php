<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Http\MeJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The client's view of its own account. The response shape is hand-built in
 * {@see MeJson}, not serialised from the entity — see the note there.
 */
final readonly class MeController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(MeJson::profile($user));
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

        return new JsonResponse(MeJson::profile($user));
    }

    /**
     * Per-account settings. Separate from the locale PATCH because
     * UpdateLocaleRequest requires a non-blank locale: folding preferences into
     * it would force every preference write to resend the language, or cost the
     * locale its 422-on-unsupported-value guarantee (#180).
     */
    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdatePreferencesRequest $request,
    ): JsonResponse {
        $user->getPreferences()->setScrapeFallbackEnabled($request->scrapeFallbackEnabled);
        $this->entityManager->flush();

        return new JsonResponse(MeJson::profile($user));
    }
}

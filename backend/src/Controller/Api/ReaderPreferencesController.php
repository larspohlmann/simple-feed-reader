<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateFeedDirectoryScopeRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Dto\Me\UpdateSharingRequest;
use App\Entity\User;
use App\Http\MeProfileJson;
use App\Http\PartialPatchPayload;
use App\Service\Identity\Account\AccountPreferencesWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class ReaderPreferencesController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private MeProfileJson $profile,
    ) {
    }

    /** Sharing, digest, feed-directory scope and locale keep their own routes: each has validation of its own. */
    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload(serializationContext: PartialPatchPayload::CONTEXT)] UpdatePreferencesRequest $request,
    ): JsonResponse {
        $this->preferences->changeReadingSettings($user, $request->toChange());

        return new JsonResponse($this->profile->of($user));
    }

    #[Route('/api/me/sharing', name: 'api_me_update_sharing', methods: ['PATCH'])]
    public function updateSharing(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSharingRequest $request,
    ): JsonResponse {
        $this->preferences->changeSharing($user, $request->toSettings());

        return new JsonResponse($this->profile->of($user));
    }

    #[Route('/api/me/feed-directory-scope', name: 'api_me_update_feed_directory_scope', methods: ['PATCH'])]
    public function updateFeedDirectoryScope(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateFeedDirectoryScopeRequest $request,
    ): JsonResponse {
        $this->preferences->changeFeedDirectoryScope($user, $request->toScope());

        return new JsonResponse($this->profile->of($user));
    }
}

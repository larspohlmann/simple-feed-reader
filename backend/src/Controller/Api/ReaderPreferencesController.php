<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateFeedDirectoryScopeRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Dto\Me\UpdateSharingRequest;
use App\Dto\Me\UpdateTrendingRequest;
use App\Entity\User;
use App\Http\MeProfileJson;
use App\Service\Account\AccountPreferencesWriter;
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

    /**
     * Split from the locale PATCH: folding them would force every preference write to resend the language, or
     * cost the locale its 422-on-unsupported-value guarantee.
     */
    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdatePreferencesRequest $request,
    ): JsonResponse {
        $this->preferences->changeScrapeFallback($user, $request->scrapeFallbackEnabled);

        return new JsonResponse($this->profile->of($user));
    }

    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/magazine-style', name: 'api_me_update_magazine_style', methods: ['PATCH'])]
    public function updateMagazineStyle(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateMagazineStyleRequest $request,
    ): JsonResponse {
        $this->preferences->changeMagazineStyle($user, $request->magazineStyle);

        return new JsonResponse($this->profile->of($user));
    }

    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/sharing', name: 'api_me_update_sharing', methods: ['PATCH'])]
    public function updateSharing(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSharingRequest $request,
    ): JsonResponse {
        $this->preferences->changeSharing($user, $request->toSettings());

        return new JsonResponse($this->profile->of($user));
    }

    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/trending', name: 'api_me_update_trending', methods: ['PATCH'])]
    public function updateTrending(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateTrendingRequest $request,
    ): JsonResponse {
        $this->preferences->changeTrending($user, $request->trendingEnabled);

        return new JsonResponse($this->profile->of($user));
    }

    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/feed-directory-scope', name: 'api_me_update_feed_directory_scope', methods: ['PATCH'])]
    public function updateFeedDirectoryScope(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateFeedDirectoryScopeRequest $request,
    ): JsonResponse {
        $this->preferences->changeFeedDirectoryScope($user, $request->toScope());

        return new JsonResponse($this->profile->of($user));
    }
}

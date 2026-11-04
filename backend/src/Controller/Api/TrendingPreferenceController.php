<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Me\UpdateTrendingRequest;
use App\Entity\User;
use App\Http\MeProfileJson;
use App\Service\Account\AccountPreferencesWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Its own PATCH for the reason MeController::updatePreferences() gives. */
final readonly class TrendingPreferenceController
{
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private MeProfileJson $profile,
    ) {
    }

    #[Route('/api/me/trending', name: 'api_me_update_trending', methods: ['PATCH'])]
    public function update(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateTrendingRequest $request,
    ): JsonResponse {
        $this->preferences->changeTrending($user, $request->trendingEnabled);

        return new JsonResponse($this->profile->of($user));
    }
}

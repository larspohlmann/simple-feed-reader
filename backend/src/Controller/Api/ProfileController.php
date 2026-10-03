<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Recommendation\SaveProfileSettingsRequest;
use App\Entity\User;
use App\Http\ProfileRunJson;
use App\Http\ProfileSettingsJson;
use App\Http\RecommendationDebugLogJson;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Profile\ProfileDebugLogLoader;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Profile\ProfileSettingsEditor;
use App\Service\Recommendation\Profile\ProfileSettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The interest profile: its settings, a manual start, and the newest run's status and calls. */
#[Route('/api/me/ai/profile')]
final readonly class ProfileController
{
    public function __construct(
        private ProfileSettingsProvider $settings,
        private ProfileSettingsEditor $editor,
        private ProfileRunStarter $starter,
        private ProfileRunRepository $profileRuns,
        private ProfileDebugLogLoader $debugLogs,
    ) {
    }

    #[Route('', name: 'api_me_ai_profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ProfileSettingsJson::state($this->settings->forUser($user)));
    }

    #[Route('', name: 'api_me_ai_profile_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveProfileSettingsRequest $request,
    ): JsonResponse {
        $this->editor->save($user, $request->toChange());

        return new JsonResponse(ProfileSettingsJson::state($this->settings->forUser($user)));
    }

    #[Route('/runs', name: 'api_me_ai_profile_runs_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ProfileRunJson::run($this->starter->startManually($user)));
    }

    #[Route('/runs/current', name: 'api_me_ai_profile_runs_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ProfileRunJson::current($this->profileRuns->findLatestForUser($user)));
    }

    #[Route('/runs/current/log', name: 'api_me_ai_profile_runs_log', methods: ['GET'])]
    public function log(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::profileRunLog($this->debugLogs->forUser($user)));
    }
}

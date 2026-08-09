<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Recommendation\SaveRecommendationSettingsRequest;
use App\Entity\User;
use App\Http\RecommendationSettingsJson;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Service\Worker\WorkerPresence;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's recommendation settings: the effective values every
 * recommendation service reads, plus the fixed prompt layers the settings
 * card shows as read-only context.
 */
#[Route('/api/me/ai/recommendations')]
final readonly class RecommendationSettingsController
{
    public function __construct(
        private RecommendationSettingsResolver $resolver,
        private RecommendationSettingsWriter $writer,
        private WorkerPresence $presence,
    ) {
    }

    #[Route('', name: 'api_me_ai_recommendations_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationSettingsJson::state(
            $this->resolver->forUser($user),
            $this->presence->isRecommendationWorkerAlive(),
        ));
    }

    #[Route('', name: 'api_me_ai_recommendations_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveRecommendationSettingsRequest $request,
    ): JsonResponse {
        $this->writer->save($user, $request->values());

        return new JsonResponse(RecommendationSettingsJson::state(
            $this->resolver->forUser($user),
            $this->presence->isRecommendationWorkerAlive(),
        ));
    }
}

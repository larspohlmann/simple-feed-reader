<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trending\SaveStorySettingsRequest;
use App\Entity\User;
use App\Http\StorySettingsJson;
use App\Service\Curation\StoryAi\StorySettingsEditor;
use App\Service\Curation\StoryAi\StorySettingsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The story AI settings: which connection writes story text, and the story-titles switch. */
#[Route('/api/me/ai/stories')]
final readonly class StorySettingsController
{
    public function __construct(
        private StorySettingsProvider $settings,
        private StorySettingsEditor $editor,
    ) {
    }

    #[Route('', name: 'api_me_ai_stories_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(StorySettingsJson::state($this->settings->forUser($user)));
    }

    #[Route('', name: 'api_me_ai_stories_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveStorySettingsRequest $request,
    ): JsonResponse {
        $this->editor->save($user, $request->toChange());

        return new JsonResponse(StorySettingsJson::state($this->settings->forUser($user)));
    }
}

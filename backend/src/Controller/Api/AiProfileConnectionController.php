<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Recommendation\Profile\ProfileConnectionChooser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Which saved connection distils the profile for an engine that cannot. No provider call, so no rate limit. */
#[Route('/api/me/ai/configs/{id}/profile', requirements: ['id' => '\d+'])]
final readonly class AiProfileConnectionController
{
    public function __construct(
        private AiConfigurationForUser $configuration,
        private ProfileConnectionChooser $profileConnections,
        private AiSettingsJson $settingsJson,
    ) {
    }

    #[Route('', name: 'api_me_ai_choose_profile', methods: ['PUT'])]
    public function choose(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $configuration = $this->configuration->require($user, $id);
        $this->profileConnections->choose($configuration);

        return new JsonResponse($this->settingsJson->configurationFor($configuration, $user));
    }

    #[Route('', name: 'api_me_ai_clear_profile', methods: ['DELETE'])]
    public function clear(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->profileConnections->clear($this->configuration->require($user, $id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

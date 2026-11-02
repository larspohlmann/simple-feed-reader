<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Http\SocialNetworksJson;
use App\Repository\ForYouNetworkExclusionRepository;
use App\Service\Recommendation\Feed\ForYouNetworks;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/for-you/excluded-networks')]
final readonly class ForYouNetworksController
{
    public function __construct(
        private ForYouNetworkExclusionRepository $excludedNetworks,
        private ForYouNetworks $networks,
    ) {
    }

    #[Route('', name: 'api_for_you_excluded_networks', methods: ['GET'])]
    public function excludedNetworks(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetworksJson::values(
                $this->excludedNetworks->excludedNetworksFor($user->requireId()),
            ),
        ]);
    }

    #[Route('/{network}', name: 'api_for_you_exclude_network', methods: ['PUT'])]
    public function excludeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->exclude($user, $network);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{network}', name: 'api_for_you_include_network', methods: ['DELETE'])]
    public function includeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->include($user, $network);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

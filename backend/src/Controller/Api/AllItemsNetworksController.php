<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\NetworkExclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use App\Service\Reading\NetworkExclusions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/all-items/excluded-networks')]
final readonly class AllItemsNetworksController
{
    public function __construct(
        private NetworkExclusionRepository $excludedNetworks,
        private NetworkExclusions $networks,
    ) {
    }

    #[Route('', name: 'api_all_items_excluded_networks', methods: ['GET'])]
    public function excludedNetworks(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetwork::valuesOf(
                $this->excludedNetworks->excludedNetworks($user->requireId(), NetworkExclusionSurface::AllItems),
            ),
        ]);
    }

    #[Route('/{network}', name: 'api_all_items_exclude_network', methods: ['PUT'])]
    public function excludeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->exclude($user, $network, NetworkExclusionSurface::AllItems);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{network}', name: 'api_all_items_include_network', methods: ['DELETE'])]
    public function includeNetwork(SocialNetwork $network, #[CurrentUser] User $user): JsonResponse
    {
        $this->networks->include($user, $network, NetworkExclusionSurface::AllItems);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

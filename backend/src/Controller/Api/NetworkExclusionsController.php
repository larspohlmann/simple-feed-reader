<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use App\Service\Library\Reading\NetworkExclusions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The network switches of every surface; the `surface` path segment names the one a request switches. */
#[Route('/api/{surface}/excluded-networks', requirements: [
    'surface' => new EnumRequirement([
        InclusionSurface::AllItems,
        InclusionSurface::ForYou,
        InclusionSurface::Trending,
    ]),
])]
final readonly class NetworkExclusionsController
{
    public function __construct(
        private NetworkExclusionRepository $excludedNetworks,
        private NetworkExclusions $networks,
    ) {
    }

    #[Route('', name: 'api_excluded_networks', methods: ['GET'])]
    public function excludedNetworks(InclusionSurface $surface, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetwork::valuesOf(
                $this->excludedNetworks->excludedNetworks($user->requireId(), $surface),
            ),
        ]);
    }

    #[Route('/{network}', name: 'api_exclude_network', methods: ['PUT'])]
    public function excludeNetwork(
        InclusionSurface $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->exclude($user, $network, $surface);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{network}', name: 'api_include_network', methods: ['DELETE'])]
    public function includeNetwork(
        InclusionSurface $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->include($user, $network, $surface);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

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

/** The network switches of every surface; each route's `surface` default names the one it switches. */
final readonly class NetworkExclusionsController
{
    private const string ALL_ITEMS = '/api/all-items/excluded-networks';
    private const string FOR_YOU = '/api/for-you/excluded-networks';
    private const string TRENDING = '/api/trending/excluded-networks';

    public function __construct(
        private NetworkExclusionRepository $excludedNetworks,
        private NetworkExclusions $networks,
    ) {
    }

    #[Route(self::ALL_ITEMS, name: 'api_all_items_excluded_networks', methods: ['GET'], defaults: [
        'surface' => NetworkExclusionSurface::AllItems->value,
    ])]
    #[Route(self::FOR_YOU, name: 'api_for_you_excluded_networks', methods: ['GET'], defaults: [
        'surface' => NetworkExclusionSurface::ForYou->value,
    ])]
    #[Route(self::TRENDING, name: 'api_trending_excluded_networks', methods: ['GET'], defaults: [
        'surface' => NetworkExclusionSurface::Trending->value,
    ])]
    public function excludedNetworks(NetworkExclusionSurface $surface, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetwork::valuesOf(
                $this->excludedNetworks->excludedNetworks($user->requireId(), $surface),
            ),
        ]);
    }

    #[Route(self::ALL_ITEMS . '/{network}', name: 'api_all_items_exclude_network', methods: ['PUT'], defaults: [
        'surface' => NetworkExclusionSurface::AllItems->value,
    ])]
    #[Route(self::FOR_YOU . '/{network}', name: 'api_for_you_exclude_network', methods: ['PUT'], defaults: [
        'surface' => NetworkExclusionSurface::ForYou->value,
    ])]
    #[Route(self::TRENDING . '/{network}', name: 'api_trending_exclude_network', methods: ['PUT'], defaults: [
        'surface' => NetworkExclusionSurface::Trending->value,
    ])]
    public function excludeNetwork(
        NetworkExclusionSurface $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->exclude($user, $network, $surface);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(self::ALL_ITEMS . '/{network}', name: 'api_all_items_include_network', methods: ['DELETE'], defaults: [
        'surface' => NetworkExclusionSurface::AllItems->value,
    ])]
    #[Route(self::FOR_YOU . '/{network}', name: 'api_for_you_include_network', methods: ['DELETE'], defaults: [
        'surface' => NetworkExclusionSurface::ForYou->value,
    ])]
    #[Route(self::TRENDING . '/{network}', name: 'api_trending_include_network', methods: ['DELETE'], defaults: [
        'surface' => NetworkExclusionSurface::Trending->value,
    ])]
    public function includeNetwork(
        NetworkExclusionSurface $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->include($user, $network, $surface);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

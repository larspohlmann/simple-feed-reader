<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Http\ExclusionSurfaceSlug;
use App\Repository\NetworkExclusionRepository;
use App\Service\Reading\NetworkExclusions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The network switches of every surface; the `surface` path segment names the one a request switches. */
final readonly class NetworkExclusionsController
{
    private const string LIST = '/api/{surface}/excluded-networks';
    private const string ONE = self::LIST . '/{network}';

    public function __construct(
        private NetworkExclusionRepository $excludedNetworks,
        private NetworkExclusions $networks,
    ) {
    }

    #[Route(self::LIST, name: 'api_excluded_networks', methods: ['GET'], requirements: [
        'surface' => new EnumRequirement(ExclusionSurfaceSlug::class),
    ])]
    public function excludedNetworks(ExclusionSurfaceSlug $surface, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'excludedNetworks' => SocialNetwork::valuesOf(
                $this->excludedNetworks->excludedNetworks($user->requireId(), $surface->surface()),
            ),
        ]);
    }

    #[Route(self::ONE, name: 'api_exclude_network', methods: ['PUT'], requirements: [
        'surface' => new EnumRequirement(ExclusionSurfaceSlug::class),
    ])]
    public function excludeNetwork(
        ExclusionSurfaceSlug $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->exclude($user, $network, $surface->surface());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(self::ONE, name: 'api_include_network', methods: ['DELETE'], requirements: [
        'surface' => new EnumRequirement(ExclusionSurfaceSlug::class),
    ])]
    public function includeNetwork(
        ExclusionSurfaceSlug $surface,
        SocialNetwork $network,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->networks->include($user, $network, $surface->surface());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

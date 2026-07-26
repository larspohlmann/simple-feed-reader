<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\FeedRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final class CatalogController
{
    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly FeedRepository $feeds,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser((int) $user->getId()),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\FeedRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final class CatalogController
{
    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly FeedRepository $feeds,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $userId = (int) $user->getId();

        // A pure read: clearing first costs nothing here and guards the
        // fetch-joined query below against Doctrine's identity-map
        // short-circuit, which would otherwise hand back a CatalogCategory
        // already in this request's identity map with its original (pre-join)
        // feeds collection instead of the one the query just loaded.
        $this->em->clear();

        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser($userId),
        ));
    }
}

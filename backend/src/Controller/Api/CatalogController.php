<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogFaviconResponse;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Repository\FeedRepository;
use App\Service\Catalog\CatalogFaviconSource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final readonly class CatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private FeedRepository $feeds,
        private CatalogFeedRepository $catalogFeeds,
        private CatalogFaviconSource $favicons,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser($user->requireId()),
        ));
    }

    #[Route('/feeds/{id}/favicon', name: 'api_catalog_favicon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function favicon(int $id): Response
    {
        return CatalogFaviconResponse::of($this->favicons->imageFor($this->catalogFeeds->getById($id)));
    }
}

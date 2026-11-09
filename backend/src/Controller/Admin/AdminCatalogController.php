<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/catalog')]
final readonly class AdminCatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::listing(
            $this->categories->findAllOrdered(),
            $this->feeds->findAllOrdered(),
        ));
    }
}

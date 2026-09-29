<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/catalog')]
final readonly class AdminCatalogController
{
    /** Comfortably inside any sane PHP max_execution_time, and long enough that
     *  111 icons take a handful of polls rather than dozens. */
    private const int WARM_BUDGET_SECONDS = 15;

    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private CatalogFaviconWarmer $warmer,
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

    /**
     * One budgeted slice of favicon warming, polled until `remaining` is 0, as /api/refresh is. It makes icons a
     * property of the app: an install that never runs a console command still gets them.
     */
    #[Route('/favicons/warm', name: 'api_admin_catalog_warm_favicons', methods: ['POST'])]
    public function warmFavicons(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::warmReport($this->warmer->warm(self::WARM_BUDGET_SECONDS)));
    }
}

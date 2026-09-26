<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog-wide administration: the full listing and the budgeted favicon warm.
 * Per-resource CRUD lives in AdminCatalogCategoryController and
 * AdminCatalogFeedController.
 *
 * Access is enforced by ROLE_ADMIN on ^/api/admin/ in the firewall, consistent
 * with AdminUserController.
 */
#[Route('/api/admin/catalog')]
final class AdminCatalogController
{
    /** Comfortably inside any sane PHP max_execution_time, and long enough that
     *  111 icons take a handful of polls rather than dozens. */
    private const int WARM_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly CatalogFeedRepository $feeds,
        private readonly CatalogFaviconWarmer $warmer,
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

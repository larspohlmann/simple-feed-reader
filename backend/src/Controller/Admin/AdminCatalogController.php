<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
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
        return new JsonResponse([
            'categories' => array_map(
                static fn (CatalogCategory $c) => AdminCatalogJson::category($c),
                $this->categories->findAllOrdered(),
            ),
            'feeds' => array_map(
                static fn (CatalogFeed $f) => AdminCatalogJson::feed($f),
                $this->feeds->findBy([], ['position' => 'ASC', 'title' => 'ASC']),
            ),
        ]);
    }

    /**
     * One budgeted slice of favicon warming. The admin UI polls this until
     * `remaining` reaches 0 — the same contract /api/refresh uses, and for the
     * same reason: 111 publisher round trips cannot fit in one request.
     *
     * This is what makes icons a property of the app rather than of one
     * deployment: an install that never runs a console command still gets them.
     */
    #[Route('/favicons/warm', name: 'api_admin_catalog_warm_favicons', methods: ['POST'])]
    public function warmFavicons(): JsonResponse
    {
        $report = $this->warmer->warm(self::WARM_BUDGET_SECONDS);

        return new JsonResponse([
            'warmed' => $report->warmed,
            'failed' => $report->failed,
            'remaining' => $report->remaining,
        ]);
    }
}

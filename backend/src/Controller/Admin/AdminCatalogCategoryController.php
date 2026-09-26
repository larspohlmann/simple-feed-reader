<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Service\Catalog\CatalogCategoryEditor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog category CRUD and reorder. Access is enforced by ROLE_ADMIN on
 * ^/api/admin/ in the firewall, consistent with AdminUserController.
 *
 * Note the `locked` default in CatalogCategoryRequest: a row an admin creates
 * BY HAND is locked unless they say otherwise. They meant to add it, and a
 * later `replace` import should not quietly take it away again. Rows created by
 * an import are unlocked, because the document already owns them.
 */
#[Route('/api/admin/catalog/categories')]
final readonly class AdminCatalogCategoryController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogCategoryEditor $editor,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_category_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['category' => AdminCatalogJson::category($this->editor->create($request))],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/reorder', name: 'api_admin_catalog_category_reorder', methods: ['PATCH'])]
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        $this->editor->reorder($request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_admin_catalog_category_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->getById($id);
        $this->editor->update($category, $request);

        return new JsonResponse(['category' => AdminCatalogJson::category($category)]);
    }

    #[Route('/{id}', name: 'api_admin_catalog_category_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->editor->delete($this->categories->getById($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

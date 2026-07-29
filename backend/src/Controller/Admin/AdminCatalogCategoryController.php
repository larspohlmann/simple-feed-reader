<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_category_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = new CatalogCategory($request->key, $request->name, $request->icon, $request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->em->persist($category);
        $this->em->flush();

        return new JsonResponse(
            ['category' => AdminCatalogJson::category($category)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/reorder', name: 'api_admin_catalog_category_reorder', methods: ['PATCH'])]
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $this->requireCategory($id)->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_admin_catalog_category_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, #[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = $this->requireCategory($id);
        $category->setName($request->name);
        $category->setIcon($request->icon);
        $category->setColor($request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $this->em->flush();

        return new JsonResponse(['category' => AdminCatalogJson::category($category)]);
    }

    #[Route('/{id}', name: 'api_admin_catalog_category_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $category = $this->requireCategory($id);
        // Its feeds go with it via the FK's ON DELETE CASCADE. Subscriptions a
        // user already made are untouched: they are Feed rows, not catalog rows.
        $this->em->remove($category);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function requireCategory(int $id): CatalogCategory
    {
        return $this->categories->find($id) ?? throw new NotFoundHttpException('No such category.');
    }
}

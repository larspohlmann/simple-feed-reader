<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use App\Service\Catalog\CatalogFeedEditor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/catalog/feeds')]
final readonly class AdminCatalogFeedController
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogFaviconWarmer $warmer,
        private CatalogFeedEditor $editor,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_feed_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['feed' => AdminCatalogJson::feed($this->editor->create($request->toDetails()))],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/reorder', name: 'api_admin_catalog_feed_reorder', methods: ['PATCH'])]
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        $this->editor->reorder($request->ids);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_admin_catalog_feed_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $feed = $this->feeds->getById($id);
        $this->editor->update($feed, $request->toDetails());

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }

    #[Route('/{id}', name: 'api_admin_catalog_feed_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->editor->delete($this->feeds->getById($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Re-fetch one row's icon, for a publisher that changed its icon.
     * A failed fetch is a recorded failure and a 200 — the same outcome the warm
     * command produces — not a 500.
     */
    #[Route('/{id}/favicon', name: 'api_admin_catalog_feed_favicon', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refreshFavicon(int $id): JsonResponse
    {
        $feed = $this->feeds->getById($id);

        $this->warmer->refresh($feed);

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }
}

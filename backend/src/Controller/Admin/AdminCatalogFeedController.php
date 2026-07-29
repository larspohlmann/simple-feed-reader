<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog feed CRUD, reorder, and per-feed favicon refresh. Access is enforced
 * by ROLE_ADMIN on ^/api/admin/ in the firewall, consistent with
 * AdminUserController.
 *
 * Note the `locked` default in CatalogFeedRequest: a row an admin creates BY
 * HAND is locked unless they say otherwise. They meant to add it, and a later
 * `replace` import should not quietly take it away again. Rows created by an
 * import are unlocked, because the document already owns them.
 */
#[Route('/api/admin/catalog/feeds')]
final readonly class AdminCatalogFeedController
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private CatalogFaviconWarmer $warmer,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_feed_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $category = $this->requireCategory($request->categoryId);

        $feed = new CatalogFeed($category, $request->title, $request->url);
        $this->applyFeed($feed, $request);
        $feed->setPosition($this->feeds->nextPositionInCategory((int) $category->getId()));
        $this->em->persist($feed);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)], Response::HTTP_CREATED);
    }

    #[Route('/reorder', name: 'api_admin_catalog_feed_reorder', methods: ['PATCH'])]
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $this->requireFeed($id)->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'api_admin_catalog_feed_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $feed = $this->requireFeed($id);
        $category = $this->requireCategory($request->categoryId);

        $feed->setCategory($category);
        $feed->setTitle($request->title);
        $feed->setUrl($request->url);
        $this->applyFeed($feed, $request);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }

    #[Route('/{id}', name: 'api_admin_catalog_feed_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $feed = $this->requireFeed($id);
        $this->em->remove($feed);
        $this->em->flush();

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
        $feed = $this->requireFeed($id);

        // The warmer resolves, downloads, records the outcome and flushes. A dead
        // icon is a recorded failure, not a 500 — so refresh() never throws here.
        $this->warmer->refresh($feed);

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
    }

    private function applyFeed(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setSiteUrl($request->siteUrl);
        $feed->setDescription($request->description);
        $feed->setSourceFormat($request->sourceFormat);
        $feed->setEnabled($request->enabled);
        $feed->setLocked($request->locked);
    }

    private function requireFeed(int $id): CatalogFeed
    {
        return $this->feeds->find($id) ?? throw new NotFoundHttpException('No such feed.');
    }

    private function requireCategory(int $id): CatalogCategory
    {
        return $this->categories->find($id) ?? throw new NotFoundHttpException('No such category.');
    }
}

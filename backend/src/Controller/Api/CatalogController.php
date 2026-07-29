<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Repository\FeedRepository;
use App\Service\Catalog\MonogramFavicon;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final readonly class CatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private FeedRepository $feeds,
        private CatalogFeedRepository $catalogFeeds,
        private MonogramFavicon $monogram,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser((int) $user->getId()),
        ));
    }

    /**
     * Cached bytes, or the monogram on a miss. NEVER fetches: a cache miss here
     * is a normal state, filled by app:catalog:warm-favicons at deploy time.
     * The long max-age is safe because the URL is per-feed-id and the ETag
     * changes whenever the bytes do.
     */
    #[Route('/feeds/{id}/favicon', name: 'api_catalog_favicon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function favicon(int $id): Response
    {
        $feed = $this->catalogFeeds->find($id) ?? throw new NotFoundHttpException('No such catalog feed.');

        $bytes = $feed->getFaviconBytes();
        $contentType = $feed->getFaviconContentType();

        if (null === $bytes || null === $contentType) {
            $bytes = $this->monogram->render($feed);
            $contentType = MonogramFavicon::CONTENT_TYPE;
        }

        $response = new Response($bytes, Response::HTTP_OK, ['Content-Type' => $contentType]);
        $response->setEtag(md5($bytes));
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}

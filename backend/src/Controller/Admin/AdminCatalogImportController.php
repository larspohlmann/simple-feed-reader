<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\CatalogImportModeRequest;
use App\Dto\Admin\CatalogImportRequest;
use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportResult;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog import. Access is enforced by ROLE_ADMIN on ^/api/admin/ in the
 * firewall, consistent with the other admin controllers.
 */
#[Route('/api/admin/catalog')]
final readonly class AdminCatalogImportController
{
    public function __construct(
        private CatalogImporter $importer,
        private CatalogDocument $parser,
        private BundledCatalog $bundled,
    ) {
    }

    /**
     * What the shipped document would import, without importing it. Lets the
     * admin UI label its one-click button with real numbers instead of asking
     * the admin to take it on faith.
     */
    #[Route('/bundled', name: 'api_admin_catalog_bundled', methods: ['GET'])]
    public function describeBundled(): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException) {
            // Missing or corrupt: report it as unavailable rather than 500. The
            // admin can still upload a file, which is the more useful answer.
            return new JsonResponse(['available' => false, 'categories' => 0, 'feeds' => 0]);
        }

        return new JsonResponse([
            'available' => true,
            'categories' => \count($document->categories),
            'feeds' => $document->feedCount(),
        ]);
    }

    /**
     * Import the document this release ships — no upload. The common case is an
     * admin who has just been told the catalog is empty; making them locate a
     * file that is already on the server would be busywork.
     */
    #[Route('/import/bundled', name: 'api_admin_catalog_import_bundled', methods: ['POST'])]
    public function importBundled(#[MapRequestPayload] CatalogImportModeRequest $request): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->respond($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        ));
    }

    #[Route('/import', name: 'api_admin_catalog_import', methods: ['POST'])]
    public function import(#[MapRequestPayload] CatalogImportRequest $request): JsonResponse
    {
        try {
            $document = $this->parser->parse($request->document);
        } catch (InvalidCatalogDocumentException $e) {
            // 422, not 500: the upload is the user's input, and nothing was
            // written — validation happens entirely before the importer runs.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->respond($this->importer->import(
            $document,
            $request->mode ?? throw new UnprocessableEntityHttpException('A mode is required.'),
        ));
    }

    private function respond(CatalogImportResult $result): JsonResponse
    {
        return new JsonResponse([
            'categoriesCreated' => $result->categoriesCreated,
            'categoriesUpdated' => $result->categoriesUpdated,
            'categoriesRemoved' => $result->categoriesRemoved,
            'feedsCreated' => $result->feedsCreated,
            'feedsUpdated' => $result->feedsUpdated,
            'feedsRemoved' => $result->feedsRemoved,
            'lockedSkipped' => $result->lockedSkipped,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Opml\OpmlImportRequest;
use App\Dto\Opml\OpmlPreviewRequest;
use App\Entity\User;
use App\Http\OpmlJson;
use App\Service\Library\Opml\OpmlExporter;
use App\Service\Library\Opml\OpmlImporter;
use App\Service\Library\Opml\OpmlPreviewer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final readonly class OpmlController
{
    public function __construct(
        private OpmlExporter $exporter,
        private OpmlImporter $importer,
        private OpmlPreviewer $previewer,
    ) {
    }

    /**
     * @throws \DOMException
     */
    #[Route('/export', name: 'api_opml_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user): Response
    {
        $xml = $this->exporter->export($user);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'text/x-opml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="feeds.opml"',
        ]);
    }

    #[Route('/preview', name: 'api_opml_preview', methods: ['POST'])]
    public function preview(#[CurrentUser] User $user, #[MapRequestPayload] OpmlPreviewRequest $request): JsonResponse
    {
        return new JsonResponse(
            OpmlJson::preview($this->previewer->preview($user, $request->opml, $request->toChoice())),
        );
    }

    #[Route('/import', name: 'api_opml_import', methods: ['POST'])]
    public function import(#[CurrentUser] User $user, #[MapRequestPayload] OpmlImportRequest $request): JsonResponse
    {
        return new JsonResponse(OpmlJson::imported($this->importer->import($user, $request->toSelection())));
    }
}

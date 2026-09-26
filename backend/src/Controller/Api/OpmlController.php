<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\OpmlJson;
use App\Service\Opml\OpmlExporter;
use App\Service\Opml\OpmlImporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final readonly class OpmlController
{
    public function __construct(
        private OpmlExporter $exporter,
        private OpmlImporter $importer,
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

    #[Route('/import', name: 'api_opml_import', methods: ['POST'])]
    public function import(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(OpmlJson::imported($this->importer->import($user, $request->getContent())));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlExporter;
use App\Service\Opml\OpmlImporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final class OpmlController
{
    public function __construct(
        private readonly OpmlExporter $exporter,
        private readonly OpmlImporter $importer,
    ) {
    }

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
        $body = $request->getContent();
        if ($body === '' || \strlen($body) > 1_048_576) {
            throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');
        }

        $result = $this->importer->import($user, $body);

        return new JsonResponse([
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
        ]);
    }
}

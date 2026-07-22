<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Opml\OpmlExporter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final class OpmlController
{
    public function __construct(
        private readonly OpmlExporter $exporter,
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
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\EntryJson;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRowEnricher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryDetailController
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private EntryJson $entryJson,
    ) {
    }

    #[Route('/{id}', name: 'api_entries_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->entryList->getRowForUser($user->requireId(), $id);
        $row = $this->enricher->enrich([$row], $user->requireId())[0];

        return new JsonResponse([
            'entry' => $this->entryJson->detail($row),
            'completed' => $this->entryJson->completed($row),
        ]);
    }
}

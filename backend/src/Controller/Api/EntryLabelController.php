<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\LabelRepository;
use App\Service\Library\Label\EntryLabeling;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries/{id}/labels/{labelId}', requirements: ['id' => '\d+', 'labelId' => '\d+'])]
final readonly class EntryLabelController
{
    public function __construct(
        private EntryListRepository $entryList,
        private LabelRepository $labels,
        private EntryLabeling $labeling,
    ) {
    }

    #[Route('', name: 'api_entry_labels_attach', methods: ['PUT'])]
    public function attach(int $id, int $labelId, #[CurrentUser] User $user): JsonResponse
    {
        $this->labeling->attach(
            $this->labels->requireOneForUser($user->requireId(), $labelId),
            $this->entryList->getRowForUser($user->requireId(), $id)->entry,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('', name: 'api_entry_labels_detach', methods: ['DELETE'])]
    public function detach(int $id, int $labelId, #[CurrentUser] User $user): JsonResponse
    {
        $this->labeling->detach(
            $this->labels->requireOneForUser($user->requireId(), $labelId),
            $this->entryList->getRowForUser($user->requireId(), $id)->entry,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

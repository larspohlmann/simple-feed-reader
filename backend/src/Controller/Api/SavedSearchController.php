<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Dto\SavedSearch\UpdateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\SavedSearchEditor;
use App\Service\Search\SavedSearchTallies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/saved-searches')]
final readonly class SavedSearchController
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchTallies $tallies,
        private SavedSearchEditor $editor,
    ) {
    }

    #[Route('', name: 'api_saved_searches_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $userId = $user->requireId();
        $rows = $this->savedSearches->findForUser($userId);
        $tallies = $this->tallies->forAll($rows, $userId);

        return new JsonResponse([
            'savedSearches' => array_map(
                static fn (SavedSearch $s) => SavedSearchJson::one($s, $tallies[$s->requireId()]),
                $rows,
            ),
        ]);
    }

    #[Route('', name: 'api_saved_searches_create', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = $user->requireId();
        $outcome = $this->editor->save($user, $request);
        $savedSearch = $outcome->savedSearch;

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->tallies->forOne($savedSearch, $userId))],
            $outcome->isNew ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route('/{id}', name: 'api_saved_searches_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneForUser($userId, $id);

        $this->editor->changeDigestInclusion($savedSearch, $request);

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->tallies->forOne($savedSearch, $userId))],
        );
    }

    #[Route('/{id}', name: 'api_saved_searches_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $savedSearch = $this->savedSearches->getOneForUser($user->requireId(), $id);

        $this->editor->delete($savedSearch);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Dto\SavedSearch\UpdateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\SavedSearchMatchIds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/saved-searches')]
final readonly class SavedSearchController
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchMatchIds $matches,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_saved_searches_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $userId = (int) $user->getId();
        $rows = $this->savedSearches->findForUser($userId);
        $idsBySearch = $this->matches->forAll($rows, $userId);

        return new JsonResponse([
            'savedSearches' => array_map(
                static fn (SavedSearch $s) => SavedSearchJson::one($s, $idsBySearch[(int) $s->getId()] ?? []),
                $rows,
            ),
        ]);
    }

    #[Route('', name: 'api_saved_searches_create', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = (int) $user->getId();
        // Saving a term already saved is idempotent, and answers 200 with the
        // row that was there rather than 201 with a second one.
        $savedSearch = $this->savedSearches->findOneForUserByTerm(
            $userId,
            $request->term,
            $request->wholeWord,
            $request->phrase,
        );
        $status = $savedSearch === null ? Response::HTTP_CREATED : Response::HTTP_OK;

        if ($savedSearch === null) {
            $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord, $request->phrase);
            $this->em->persist($savedSearch);
            $this->em->flush();
        }

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->matches->forOne($savedSearch, $userId))],
            $status,
        );
    }

    #[Route('/{id}', name: 'api_saved_searches_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = (int) $user->getId();
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such saved search.');

        $savedSearch->setIncludeInDigest($request->includeInDigest);
        $this->em->flush();

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->matches->forOne($savedSearch, $userId))],
        );
    }

    #[Route('/{id}', name: 'api_saved_searches_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such saved search.');

        $this->em->remove($savedSearch);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\SavedSearchMatchCounter;
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
        private SavedSearchMatchCounter $counter,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_saved_searches_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $userId = (int) $user->getId();
        $rows = $this->savedSearches->findForUser($userId);
        $counts = $this->counter->countsFor($rows, $userId);

        return new JsonResponse([
            'savedSearches' => array_map(
                static fn (SavedSearch $s) => SavedSearchJson::one($s, $counts[(int) $s->getId()] ?? 0),
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
        $existing = $this->savedSearches->findOneForUserByTerm($userId, $request->term, $request->wholeWord);
        if ($existing !== null) {
            return new JsonResponse(
                ['savedSearch' => SavedSearchJson::one($existing, $this->counter->countFor($existing, $userId))],
                Response::HTTP_OK,
            );
        }

        $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord);
        $this->em->persist($savedSearch);
        $this->em->flush();

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->counter->countFor($savedSearch, $userId))],
            Response::HTTP_CREATED,
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

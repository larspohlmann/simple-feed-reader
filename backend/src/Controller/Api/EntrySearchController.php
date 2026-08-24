<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Search\MarkSearchReadRequest;
use App\Entity\User;
use App\Http\SearchPage;
use App\Service\Reader\SearchMarkReadService;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\EntrySearchRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries/search')]
final readonly class EntrySearchController
{
    public function __construct(
        private EntrySearchInterface $search,
        private EntrySearchRequestFactory $requests,
        private SearchMarkReadService $searchMarkRead,
    ) {
    }

    #[Route('', name: 'api_entries_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = $this->requests->fromRequest($request, $user);

        return new JsonResponse(SearchPage::of($this->search->search($query), $query->limit));
    }

    #[Route('/mark-read', name: 'api_entries_search_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSearchReadRequest $request,
    ): JsonResponse {
        $this->searchMarkRead->mark($user, $request->q, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

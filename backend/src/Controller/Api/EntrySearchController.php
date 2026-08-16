<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SearchPage;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\EntrySearchRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries/search')]
final readonly class EntrySearchController
{
    public function __construct(
        private EntrySearchInterface $search,
        private EntrySearchRequestFactory $requests,
    ) {
    }

    #[Route('', name: 'api_entries_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = $this->requests->fromRequest($request, $user);

        return new JsonResponse(SearchPage::of($this->search->search($query), $query->limit));
    }
}

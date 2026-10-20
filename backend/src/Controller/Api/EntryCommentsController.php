<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CommentsJson;
use App\Repository\EntryListRepository;
use App\Service\Comments\CommentsLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCommentsController
{
    public function __construct(
        private EntryListRepository $entryList,
        private CommentsLoader $comments,
        private CommentsJson $commentsJson,
    ) {
    }

    #[Route('/{id}/comments', name: 'api_entries_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function comments(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $result = $request->query->getBoolean('refresh')
            ? $this->comments->refreshed($user, $entry)
            : $this->comments->load($user, $entry);

        return new JsonResponse($this->commentsJson->one($result));
    }
}

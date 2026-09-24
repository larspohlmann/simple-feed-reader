<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CommentsJson;
use App\Repository\EntryListRepository;
use App\Service\Comments\CommentsLoader;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCommentsController
{
    public function __construct(
        private EntryListRepository $entryList,
        private CommentsLoader $comments,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    #[Route('/{id}/comments', name: 'api_entries_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function comments(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $user);

        try {
            $result = $this->comments->load($entry);
        } catch (NoCommentsFeedException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(CommentsJson::one($result, $entry->getDiscussion()->url));
    }
}

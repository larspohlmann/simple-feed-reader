<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Discussion\VoteRequest;
use App\Entity\User;
use App\Http\DiscussionVoteJson;
use App\Repository\EntryListRepository;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Social\DiscussionVotes;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryVoteController
{
    public function __construct(
        private EntryListRepository $entryList,
        private DiscussionVotes $discussionVotes,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialLikeLimiter,
        #[Target]
        private RateLimiterFactoryInterface $socialViewerLimiter,
    ) {
    }

    #[Route('/{id}/vote', name: 'api_entries_vote', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function vote(int $id, #[MapRequestPayload] VoteRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        return new JsonResponse(
            DiscussionVoteJson::result($this->discussionVotes->vote($user, $entry, $request->toScore())),
        );
    }

    #[Route(
        '/{id}/comments/vote',
        name: 'api_entries_comment_vote',
        methods: ['PUT'],
        requirements: ['id' => '\d+'],
    )]
    public function voteOnComment(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[MapRequestPayload] VoteRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);
        $result = $this->discussionVotes->voteOnComment($user, $entry, $address, $request->toScore());

        return new JsonResponse(DiscussionVoteJson::result($result));
    }

    #[Route('/{id}/vote', name: 'api_entries_vote_read', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function read(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        return new JsonResponse(DiscussionVoteJson::myVote($this->discussionVotes->syncFromNetwork($user, $entry)));
    }
}

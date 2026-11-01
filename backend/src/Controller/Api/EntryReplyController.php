<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\ReplyRequest;
use App\Entity\User;
use App\Http\ComposerContextJson;
use App\Http\PublishedReplyJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\Model\ReplyTargetModel;
use App\Service\Social\ReplyComposer;
use App\Service\Social\SocialReplies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryReplyController
{
    public function __construct(
        private EntryListRepository $entryList,
        private ReplyComposer $composer,
        private SocialReplies $replies,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialViewerLimiter,
        private RateLimiterFactoryInterface $socialWritesLimiter,
    ) {
    }

    #[Route(
        '/{id}/social/composer',
        name: 'api_entries_social_composer',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function composer(
        int $id,
        #[CurrentUser] User $user,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?string $address = null,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?int $accountId = null,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        $context = $this->composer->contextFor($user, new ReplyTargetModel($entry, $address, $accountId));

        return new JsonResponse(ComposerContextJson::of($context));
    }

    #[Route('/{id}/social/replies', name: 'api_entries_social_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReplyRequest $request,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?string $address = null,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $published = $this->replies->reply($user, $request->toTarget($entry, $address), $request->toDraft());

        return new JsonResponse(PublishedReplyJson::of($published), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/comments/reply',
        name: 'api_entries_comment_delete_reply',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function deleteReply(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->replies->delete($user, $entry, $address);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

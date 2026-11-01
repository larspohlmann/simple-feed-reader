<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\PostDraftRequest;
use App\Entity\User;
use App\Http\ComposerContextJson;
use App\Http\PublishedPostJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\PostComposer;
use App\Service\Social\SocialQuotes;
use App\Service\Social\SocialReplies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntrySocialPostController
{
    public function __construct(
        private EntryListRepository $entryList,
        private PostComposer $composer,
        private SocialReplies $replies,
        private SocialQuotes $quotes,
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
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        ResponseKind $kind = ResponseKind::Reply,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        $target = new ResponseTargetModel($entry, $address, $accountId);
        $context = $this->composer->contextFor($user, $target, $kind);

        return new JsonResponse(ComposerContextJson::of($context));
    }

    #[Route('/{id}/social/replies', name: 'api_entries_social_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] PostDraftRequest $request,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?string $address = null,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $published = $this->replies->reply($user, $request->toTarget($entry, $address), $request->toDraft());

        return new JsonResponse(PublishedPostJson::of($published), Response::HTTP_CREATED);
    }

    #[Route('/{id}/social/quotes', name: 'api_entries_social_quote', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function quote(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] PostDraftRequest $request,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?string $address = null,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $published = $this->quotes->quote($user, $request->toTarget($entry, $address), $request->toDraft());

        return new JsonResponse(PublishedPostJson::of($published), Response::HTTP_CREATED);
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

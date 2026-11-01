<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\LinkTargetQuery;
use App\Dto\Social\PostDraftRequest;
use App\Entity\User;
use App\Http\ComposerContextJson;
use App\Http\PublishedPostJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\LinkPosts;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/social')]
final readonly class SocialLinkPostController
{
    public function __construct(
        private LinkPosts $links,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialViewerLimiter,
        private RateLimiterFactoryInterface $socialWritesLimiter,
    ) {
    }

    #[Route('/link-composer', name: 'api_social_link_composer', methods: ['GET'])]
    public function linkComposer(
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] LinkTargetQuery $query,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?int $accountId = null,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        $context = $this->links->contextFor($user, $query->toLinkTarget($accountId));

        return new JsonResponse(ComposerContextJson::of($context));
    }

    #[Route('/links', name: 'api_social_link', methods: ['POST'])]
    public function link(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PostDraftRequest $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] LinkTargetQuery $query,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $published = $this->links->share($user, $query->toLinkTarget($request->accountId), $request->toDraft());

        return new JsonResponse(PublishedPostJson::of($published), Response::HTTP_CREATED);
    }
}

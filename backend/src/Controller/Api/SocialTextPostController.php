<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\TextPostDraftRequest;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Http\ComposerContextJson;
use App\Http\PublishedPostJson;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Publishing\Model\TextPostTargetModel;
use App\Service\Social\Publishing\TextPosts;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/social')]
final readonly class SocialTextPostController
{
    public function __construct(
        private TextPosts $textPosts,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialViewerLimiter,
        #[Target]
        private RateLimiterFactoryInterface $socialWritesLimiter,
    ) {
    }

    #[Route('/post-composer', name: 'api_social_post_composer', methods: ['GET'])]
    public function postComposer(
        #[CurrentUser] User $user,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] SocialNetwork $network,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?int $accountId = null,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        $context = $this->textPosts->prepareContext($user, new TextPostTargetModel($network, $accountId));

        return new JsonResponse(ComposerContextJson::from($context));
    }

    #[Route('/posts', name: 'api_social_post', methods: ['POST'])]
    public function post(
        #[CurrentUser] User $user,
        #[MapRequestPayload] TextPostDraftRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $published = $this->textPosts->publish($user, $request->toTarget(), $request->toPublication());

        return new JsonResponse(PublishedPostJson::from($published), Response::HTTP_CREATED);
    }
}

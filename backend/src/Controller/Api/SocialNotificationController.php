<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\MarkNotificationsSeenRequest;
use App\Dto\Social\RefreshNotificationsRequest;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Http\SocialNotificationsJson;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Notification\NotificationList;
use App\Service\Social\Notification\NotificationRefresh;
use App\Service\Social\Notification\NotificationSeenMarker;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me/notifications')]
final readonly class SocialNotificationController
{
    public function __construct(
        private NotificationList $notificationList,
        private NotificationSeenMarker $seenMarker,
        private NotificationRefresh $notificationRefresh,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialWritesLimiter,
        #[Target]
        private RateLimiterFactoryInterface $notificationRefreshLimiter,
    ) {
    }

    #[Route('', name: 'api_me_notifications', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] SocialNetwork $network,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ?int $cursor = null,
    ): JsonResponse {
        return new JsonResponse(SocialNotificationsJson::page($this->notificationList->page($user, $network, $cursor)));
    }

    #[Route('/refresh', name: 'api_me_notifications_refresh', methods: ['POST'])]
    public function refresh(
        #[MapRequestPayload] RefreshNotificationsRequest $request,
        #[CurrentUser] User $user,
    ): Response {
        $this->rateLimitGuard->enforceForUser($this->notificationRefreshLimiter, $user);

        $this->notificationRefresh->refresh($user, $request->toNetwork());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/seen', name: 'api_me_notifications_seen', methods: ['POST'])]
    public function seen(
        #[MapRequestPayload] MarkNotificationsSeenRequest $request,
        #[CurrentUser] User $user,
    ): Response {
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->seenMarker->markSeen($user, $request->toNetwork(), $request->toUpTo());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}

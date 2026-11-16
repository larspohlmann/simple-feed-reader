<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Http\SocialNotificationsJson;
use App\Service\Social\Notification\NotificationList;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me/notifications')]
final readonly class SocialNotificationController
{
    public function __construct(private NotificationList $notificationList)
    {
    }

    #[Route('', name: 'api_me_notifications', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] SocialNetwork $network,
        #[MapQueryParameter] ?int $cursor = null,
    ): JsonResponse {
        return new JsonResponse(SocialNotificationsJson::page($this->notificationList->page($user, $network, $cursor)));
    }
}

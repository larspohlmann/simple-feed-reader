<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkEntriesReadRequest;
use App\Dto\Entry\MarkForYouReadRequest;
use App\Dto\Entry\MarkReadRequest;
use App\Entity\User;
use App\Service\Reading\ForYouMarkReadService;
use App\Service\Reading\MarkEntriesReadService;
use App\Service\Reading\MarkReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryMarkReadController
{
    public function __construct(
        private MarkReadService $markRead,
        private ForYouMarkReadService $forYouMarkRead,
        private MarkEntriesReadService $markEntriesRead,
    ) {
    }

    #[Route('/mark-read', name: 'api_entries_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->toScope(), $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Its own endpoint, not a scope on `/mark-read`: that one moves a watermark per subscription, which marking the
     * for-you list must never move (ForYouMarkReadService).
     */
    #[Route('/for-you/mark-read', name: 'api_entries_for_you_mark_read', methods: ['POST'])]
    public function markForYouRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkForYouReadRequest $request,
    ): JsonResponse {
        $this->forYouMarkRead->mark($user, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/mark-read-batch', name: 'api_entries_mark_read_batch', methods: ['POST'])]
    public function markReadBatch(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkEntriesReadRequest $request,
    ): JsonResponse {
        $this->markEntriesRead->mark($user, $request->ids);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

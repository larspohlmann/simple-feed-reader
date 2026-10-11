<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\CompleteEntriesRequest;
use App\Entity\User;
use App\Http\EntryJson;
use App\Service\Completion\EntryCompletion;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCompletionController
{
    public function __construct(
        private EntryCompletion $completion,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $entryCompletionLimiter,
    ) {
    }

    #[Route('/complete', name: 'api_entries_complete', methods: ['POST'])]
    public function complete(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CompleteEntriesRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->entryCompletionLimiter, $user);

        $rows = $this->completion->complete($user->requireId(), $request->ids);

        return new JsonResponse(['entries' => array_map(EntryJson::completed(...), $rows)]);
    }
}

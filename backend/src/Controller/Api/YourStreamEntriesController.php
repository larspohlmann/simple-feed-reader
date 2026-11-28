<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\EntryPageParameters;
use App\Dto\Entry\MarkYourStreamReadRequest;
use App\Entity\User;
use App\Http\EntryPage;
use App\Repository\EntryListSort;
use App\Repository\YourStreamEntryRepository;
use App\Service\Library\EntryList\EntryListRowEnricher;
use App\Service\Library\Reading\YourStreamMarkReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Your stream: every entry the caller's interests matched, as an entry list that can be cleared. */
#[Route('/api/entries/your-stream')]
final readonly class YourStreamEntriesController
{
    public function __construct(
        private YourStreamEntryRepository $streamEntries,
        private EntryListRowEnricher $enricher,
        private EntryPage $entryPage,
        private YourStreamMarkReadService $markRead,
    ) {
    }

    #[Route('', name: 'api_entries_your_stream', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
        $userId = $user->requireId();
        $listPage = $page->toListPage();
        $rows = $this->enricher->enrich($this->streamEntries->listMembers($userId, $listPage), $userId);

        return new JsonResponse($this->entryPage->from($rows, $listPage->limit, EntryListSort::PublishedDate));
    }

    #[Route('/mark-read', name: 'api_entries_your_stream_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkYourStreamReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

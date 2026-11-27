<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\ForYouStreamEntryRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Outbound\Mail\Digest\Model\DigestSearchMatchesModel;

/**
 * The digest's read of a saved search or of the For you stream: everything unread in its membership table since the
 * last send, capped for one email section. The membership sweep keeps those tables current.
 */
final readonly class DigestEntryFinder
{
    /** The most entries one saved search contributes to a single digest. */
    public const int PER_SEARCH = 10;

    public function __construct(
        private SavedSearchEntryRepository $members,
        private EntryListRepository $entries,
        private ForYouStreamEntryRepository $stream,
    ) {
    }

    public function matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatchesModel
    {
        return $this->newestMatches(
            $userId,
            $this->members->unreadMemberIdsForUserSince($userId, $search->requireId(), $since),
        );
    }

    public function findStreamMatchesSince(int $userId, \DateTimeImmutable $since): DigestSearchMatchesModel
    {
        return $this->newestMatches($userId, $this->stream->findUnreadMemberIdsSince($userId, $since));
    }

    /** @param list<int> $ids newest first */
    private function newestMatches(int $userId, array $ids): DigestSearchMatchesModel
    {
        if ($ids === []) {
            return new DigestSearchMatchesModel([], 0);
        }

        // Hydrate only the newest PER_SEARCH: a wide window matches hundreds, and building every heavy row would
        // time the request out. totalCount stays the pre-cap count for "+N more".
        $newestIds = \array_slice($ids, 0, self::PER_SEARCH);

        return new DigestSearchMatchesModel($this->entries->rowsByIdsForUser($userId, $newestIds), \count($ids));
    }
}

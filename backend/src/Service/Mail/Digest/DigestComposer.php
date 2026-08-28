<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchRepository;

/**
 * Builds one user's digest content: a group per `includeInDigest` saved search
 * that matched something new, in the user's saved-search order. A search with
 * no matches contributes nothing, and a user with nothing to report gets no
 * digest at all — the empty-skip that keeps the mailer from sending a blank
 * email (#636).
 */
final readonly class DigestComposer
{
    private const int SUMMARY_MAX = 200;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private DigestEntryFinder $finder,
        private DigestLinkBuilder $links,
    ) {
    }

    public function compose(User $user, \DateTimeImmutable $since): ?DigestModel
    {
        $userId = (int) $user->getId();
        $groups = [];
        $total = 0;

        foreach ($this->savedSearches->findIncludedInDigestForUser($userId) as $search) {
            $matches = $this->finder->matchesSince($search, $userId, $since);
            if ($matches->totalCount === 0) {
                continue;
            }

            $groups[] = $this->group($search, $matches);
            $total += $matches->totalCount;
        }

        return $groups === [] ? null : new DigestModel($groups, $total);
    }

    private function group(SavedSearch $search, DigestSearchMatches $matches): DigestGroup
    {
        $entries = array_map(fn (EntryListRow $row): DigestEntry => $this->entry($row), $matches->entries);

        return new DigestGroup(
            $search->getTerm(),
            $matches->totalCount,
            $entries,
            $matches->totalCount > \count($entries),
            $this->links->savedSearchUrl($search->getTerm(), $search->isWholeWord()),
        );
    }

    private function entry(EntryListRow $row): DigestEntry
    {
        return new DigestEntry(
            $row->entry->getTitle(),
            $row->subscriptionTitle,
            $this->shortDescription($row),
            $this->links->entryUrl((int) $row->entry->getId()),
        );
    }

    private function shortDescription(EntryListRow $row): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($row->entry->getSummary() ?? '')) ?? '');

        return mb_strlen($text) > self::SUMMARY_MAX
            ? rtrim(mb_substr($text, 0, self::SUMMARY_MAX)) . '…'
            : $text;
    }
}

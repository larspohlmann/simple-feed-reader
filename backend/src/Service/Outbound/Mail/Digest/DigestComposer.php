<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest;

use App\Entity\User;
use App\Repository\DigestSettingsRepository;
use App\Repository\EntryListRow;
use App\Service\Foundation\Text\Support\ClippedText;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Outbound\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface;
use App\Service\Outbound\Mail\Digest\Model\DigestEntryModel;
use App\Service\Outbound\Mail\Digest\Model\DigestGroupModel;
use App\Service\Outbound\Mail\Digest\Model\DigestGroupSource;
use App\Service\Outbound\Mail\Digest\Model\DigestModel;
use App\Service\Outbound\Mail\Digest\Model\DigestSearchMatchesModel;

/**
 * One group per `includeInDigest` saved search with new matches, in the user's saved-search order, then the For you
 * stream when the user opted in. A user with nothing to report gets null, not an empty digest, so the mailer never
 * sends a blank email.
 */
final readonly class DigestComposer
{
    private const int SUMMARY_MAX = 200;

    public function __construct(
        private DigestSavedSearchesInterface $savedSearches,
        private DigestEntryFinder $finder,
        private DigestLinkBuilder $links,
        private DigestSettingsRepository $settings,
    ) {
    }

    public function compose(User $user, \DateTimeImmutable $since): ?DigestModel
    {
        $userId = $user->requireId();
        $groups = [];
        $total = 0;

        foreach ($this->savedSearches->findIncludedInDigestForUser($userId) as $search) {
            $matches = $this->finder->matchesSince($search, $userId, $since);
            if ($matches->totalCount === 0) {
                continue;
            }

            $groups[] = $this->group(
                $matches,
                $search->getTerm(),
                $this->links->savedSearchUrl($search->getTerm(), $search->isWholeWord()),
                DigestGroupSource::SavedSearch,
            );
            $total += $matches->totalCount;
        }

        $streamMatches = $this->streamMatches($user, $since);
        if ($streamMatches->totalCount > 0) {
            $groups[] = $this->group(
                $streamMatches,
                '',
                $this->links->forYouStreamUrl(),
                DigestGroupSource::ForYouStream,
            );
            $total += $streamMatches->totalCount;
        }

        return $groups === [] ? null : new DigestModel($groups, $total);
    }

    private function streamMatches(User $user, \DateTimeImmutable $since): DigestSearchMatchesModel
    {
        if (!$this->settings->forUser($user)->isForYouStreamIncluded()) {
            return new DigestSearchMatchesModel([], 0);
        }

        return $this->finder->findStreamMatchesSince($user->requireId(), $since);
    }

    private function group(
        DigestSearchMatchesModel $matches,
        string $term,
        string $moreUrl,
        DigestGroupSource $source,
    ): DigestGroupModel {
        $entries = array_map(fn (EntryListRow $row): DigestEntryModel => $this->entry($row), $matches->entries);

        return new DigestGroupModel(
            $term,
            $matches->totalCount,
            $entries,
            $matches->totalCount > \count($entries),
            $moreUrl,
            $source,
        );
    }

    private function entry(EntryListRow $row): DigestEntryModel
    {
        $entry = $row->entry;

        return new DigestEntryModel(
            $entry->getTitle(),
            $row->subscription->title,
            $this->shortDescription($row),
            $this->links->entryUrl($entry->requireId()),
            $entry->getPublishedAt(),
            $entry->getImageUrl(),
            $entry->getFeed()->getFaviconUrl(),
        );
    }

    private function shortDescription(EntryListRow $row): string
    {
        // PlainText strips markup AND decodes the entity-escaped HTML feed
        // summaries routinely carry ("&amp;#8220;"), which a bare strip_tags
        // would leak into the mail; then cap for one email line.
        $text = PlainText::from($row->entry->getText()->getSummary()) ?? '';

        return ClippedText::cutTrimmedText($text, self::SUMMARY_MAX);
    }
}

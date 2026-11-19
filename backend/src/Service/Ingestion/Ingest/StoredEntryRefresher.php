<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;

/** Brings the stored entries a fresh parse still serves up to date, matched by GUID hash. The caller flushes. */
final readonly class StoredEntryRefresher
{
    public function __construct(
        private EntryRepository $entries,
        private PlatformEntryRules $platformRules,
        private EntryImageWriter $imageWriter,
        private EntryLemmyPostWriter $lemmyPostWriter,
    ) {
    }

    public function refreshStoredEntries(Feed $feed, ParsedFeedModel $parsed): void
    {
        if ($parsed->entries === []) {
            return;
        }

        $stored = $this->entries->findByFeedIndexedByGuidHash($feed, $this->hashGuids($parsed->entries));
        $refreshed = [];
        foreach ($parsed->entries as $parsedEntry) {
            $entry = $stored[EntryGuid::hashOf($parsedEntry->guid)] ?? null;
            if ($entry === null) {
                continue;
            }
            $this->fillMissingImage($entry, $parsedEntry);
            $applied = $this->platformRules->apply($parsedEntry);
            $entry->recordDiscussionCounts($applied->discussionCounts);
            $refreshed[] = [$entry, $applied];
        }
        $this->lemmyPostWriter->refresh($refreshed);
    }

    private function fillMissingImage(Entry $entry, ParsedEntryModel $parsedEntry): void
    {
        $image = $parsedEntry->media->image;
        if ($image === null || !$entry->getImage()->isMissing()) {
            return;
        }
        $this->imageWriter->write($entry, $image);
    }

    /**
     * @param list<ParsedEntryModel> $entries
     *
     * @return list<string>
     */
    private function hashGuids(array $entries): array
    {
        return array_map(static fn (ParsedEntryModel $entry): string => EntryGuid::hashOf($entry->guid), $entries);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Ingestion\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingestion\Ingest\Model\IncomingEntryModel;
use App\Service\Ingestion\Ingest\Pass\EntryDeduplicator;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a ParsedFeedModel into Entry rows, skipping any whose GUID hash or stable URL the feed already holds, and
 * has the feed's metadata refreshed first. The caller flushes.
 */
final readonly class EntryIngestor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entries,
        private EntryUrlHash $urlHash,
        private EntryCategoryWriter $categoryWriter,
        private PlatformEntryRules $platformRules,
        private IngestedEntryFactory $entryFactory,
        private FeedMetadataRecorder $metadataRecorder,
        private EntryTranscriptWriter $transcriptWriter,
        private EntryLemmyPostWriter $lemmyPostWriter,
    ) {
    }

    /** @return list<Entry> the entries created, without ids until the caller flushes */
    public function ingest(Feed $feed, ParsedFeedModel $parsed, FeedIngestContext $context): array
    {
        $this->metadataRecorder->record($feed, $parsed);

        if ($parsed->entries === []) {
            return [];
        }

        $incoming = array_map(
            fn (ParsedEntryModel $parsedEntry): IncomingEntryModel
                => $this->incoming($this->platformRules->apply($parsedEntry)),
            $parsed->entries,
        );
        $deduplicator = $this->loadDeduplicator($feed, $incoming);

        $created = [];
        $newPairs = [];
        foreach ($incoming as $candidate) {
            if ($deduplicator->isDuplicate($candidate->guidHash, $candidate->urlHash)) {
                continue;
            }
            $deduplicator->remember($candidate->guidHash, $candidate->urlHash);

            $entry = $this->entryFactory->create($feed, $candidate, $context);
            $this->entityManager->persist($entry);
            $created[] = $entry;
            $newPairs[] = [$entry, $candidate->parsed];
        }

        $this->categoryWriter->attach($newPairs);
        $this->transcriptWriter->attach($newPairs);
        $this->lemmyPostWriter->attach($newPairs);

        return $created;
    }

    private function incoming(ParsedEntryModel $entry): IncomingEntryModel
    {
        return new IncomingEntryModel(
            $entry,
            EntryGuid::hashGuid($entry->guid),
            $this->urlHash->hashEntryUrl($entry->guid, $entry->url),
        );
    }

    /**
     * @param list<IncomingEntryModel> $incoming
     */
    private function loadDeduplicator(Feed $feed, array $incoming): EntryDeduplicator
    {
        return new EntryDeduplicator(
            $this->entries->existingGuidHashesForFeed(
                $feed->requireId(),
                array_map(static fn (IncomingEntryModel $candidate): string => $candidate->guidHash, $incoming),
            ),
            $this->entries->findExistingUrlHashes($feed, self::collectUrlHashes($incoming)),
        );
    }

    /**
     * @param list<IncomingEntryModel> $incoming
     *
     * @return list<string> the url hashes of the entries that have one: a url-less item dedupes on GUID alone
     */
    private static function collectUrlHashes(array $incoming): array
    {
        $hashes = [];
        foreach ($incoming as $candidate) {
            if ($candidate->urlHash !== null) {
                $hashes[] = $candidate->urlHash;
            }
        }

        return $hashes;
    }
}

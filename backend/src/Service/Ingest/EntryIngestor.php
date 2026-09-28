<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against the feed's
 * existing entries on stable URL (falling back to GUID hash), sanitizes
 * content, truncates to column limits, and refreshes feed metadata. Caller
 * flushes.
 */
final readonly class EntryIngestor
{
    private const int FEED_TITLE_MAX = 512;
    private const int SITE_URL_MAX = 2048;

    /**
     * feed.description is a TEXT column, so nothing but this bounds it. It is
     * reduced to plain text on every read of the sidebar bootstrap — once per
     * subscription, for the whole library — so a feed that ships its About
     * page as a <description> would tax every page load forever. Generous
     * enough that no real feed notices: the longest in a 111-feed library is
     * 617 characters.
     */
    private const int FEED_DESCRIPTION_MAX = 4000;

    public function __construct(
        private EntityManagerInterface $em,
        private EntryRepository $entryRepository,
        private UrlNormalizer $urlNormalizer,
        private EntryCategoryWriter $categoryWriter,
        private PlatformEntryRules $platformRules,
        private IngestedEntryFactory $entryFactory,
        private EntryImageWriter $imageWriter,
    ) {
    }

    /**
     * @param FeedIngestContext $context the run instant shared by every entry
     *        this call ingests, and the feed's previous fetch — together they
     *        decide where each entry lands in the list (see EntryEffectiveDate)
     *
     * @return list<Entry> the entries created, in the order the caller can
     *         later index them — each one has no id until the caller flushes
     */
    public function ingest(Feed $feed, ParsedFeed $parsed, FeedIngestContext $context): array
    {
        $this->updateFeedMetadata($feed, $parsed);

        if ($parsed->entries === []) {
            return [];
        }

        $incoming = array_map(
            fn (ParsedEntry $parsedEntry): IncomingEntry => $this->incoming($this->platformRules->apply($parsedEntry)),
            $parsed->entries,
        );
        $deduplicator = $this->deduplicatorFor($feed, $incoming);

        $created = [];
        $newPairs = [];
        foreach ($incoming as $candidate) {
            if ($deduplicator->isDuplicate($candidate->guidHash, $candidate->urlHash)) {
                continue;
            }
            $deduplicator->remember($candidate->guidHash, $candidate->urlHash);

            $entry = $this->entryFactory->create($feed, $candidate, $context);
            $this->em->persist($entry);
            $created[] = $entry;
            $newPairs[] = [$entry, $candidate->parsed];
        }

        $this->categoryWriter->attach($newPairs);

        return $created;
    }

    /**
     * Fill in the image on entries ingested before the feed's image was
     * persisted (#148), matching by guid hash against a fresh parse.
     *
     * Only entries that never had a judged image are touched — a feed that
     * later drops or downgrades its images must never erase what we have. The
     * archive this can reach is bounded by what the feed still serves (15–50
     * items against thousands stored), so this is opportunistic repair, not a
     * migration. Caller flushes. Returns the number updated.
     */
    public function fillMissingImages(Feed $feed, ParsedFeed $parsed): int
    {
        if ($parsed->entries === []) {
            return 0;
        }

        $hashes = $this->guidHashesOf($parsed->entries);
        $existing = $this->entryRepository->findByFeedIndexedByGuidHash($feed, $hashes);

        $updated = 0;
        foreach ($parsed->entries as $parsedEntry) {
            $image = $parsedEntry->media->image;
            if ($image === null) {
                continue;
            }
            $entry = $existing[self::guidHash($parsedEntry->guid)] ?? null;
            if ($entry === null || !$entry->getImage()->isMissing()) {
                continue;
            }
            if ($this->imageWriter->write($entry, $image)) {
                $updated++;
            }
        }

        return $updated;
    }

    private function updateFeedMetadata(Feed $feed, ParsedFeed $parsed): void
    {
        if ($parsed->title !== null) {
            $feed->setTitle(mb_substr($parsed->title, 0, self::FEED_TITLE_MAX));
        }
        if ($parsed->siteUrl !== null) {
            $feed->setSiteUrl(mb_substr($parsed->siteUrl, 0, self::SITE_URL_MAX));
        }
        if ($parsed->description !== null) {
            $feed->setDescription(mb_substr($parsed->description, 0, self::FEED_DESCRIPTION_MAX));
        }
        // Guarded like the fields above: a feed that stops sending its <image>
        // on one fetch must not erase the logo the reader already shows.
        // FeedImageExtractor has already applied the scheme and length rules,
        // so no truncation belongs here.
        if ($parsed->imageUrl !== null) {
            $feed->setImageUrl($parsed->imageUrl);
        }
    }

    private function incoming(ParsedEntry $entry): IncomingEntry
    {
        return new IncomingEntry($entry, self::guidHash($entry->guid), $this->urlNormalizer->hash($entry->url));
    }

    /**
     * @param list<IncomingEntry> $incoming
     */
    private function deduplicatorFor(Feed $feed, array $incoming): EntryDeduplicator
    {
        return new EntryDeduplicator(
            $this->entryRepository->existingGuidHashesForFeed(
                $feed->requireId(),
                array_map(static fn (IncomingEntry $candidate): string => $candidate->guidHash, $incoming),
            ),
            $this->entryRepository->findExistingUrlHashes($feed, self::urlHashesOf($incoming)),
        );
    }

    /**
     * @param list<ParsedEntry> $entries
     *
     * @return list<string>
     */
    private function guidHashesOf(array $entries): array
    {
        return array_map(static fn (ParsedEntry $entry): string => self::guidHash($entry->guid), $entries);
    }

    /**
     * @param list<IncomingEntry> $incoming
     *
     * @return list<string> the url hashes of the entries that have one: a url-less item dedupes on GUID alone
     */
    private static function urlHashesOf(array $incoming): array
    {
        $hashes = [];
        foreach ($incoming as $candidate) {
            if ($candidate->urlHash !== null) {
                $hashes[] = $candidate->urlHash;
            }
        }

        return $hashes;
    }

    private static function guidHash(string $guid): string
    {
        return hash('sha256', $guid);
    }
}

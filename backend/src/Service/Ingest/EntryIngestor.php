<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\ParsedImage;
use App\Service\Url\HttpsImageUrl;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against the feed's
 * existing entries on stable URL (falling back to GUID hash), sanitizes
 * content, truncates to column limits, and refreshes feed metadata. Caller
 * flushes.
 */
final class EntryIngestor
{
    private const int TITLE_MAX = 1024;
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;
    private const int FEED_TITLE_MAX = 512;

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
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
        private readonly UrlNormalizer $urlNormalizer,
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

        $deduplicator = new EntryDeduplicator(
            $this->entryRepository->findExistingGuidHashes($feed, $this->guidHashesOf($parsed->entries)),
            $this->entryRepository->findExistingUrlHashes($feed, $this->urlHashesOf($parsed->entries)),
        );

        $created = [];
        foreach ($parsed->entries as $parsedEntry) {
            $guidHash = self::guidHash($parsedEntry->guid);
            $urlHash = $this->urlHash($parsedEntry->url);
            if ($deduplicator->isDuplicate($guidHash, $urlHash)) {
                continue;
            }
            $deduplicator->remember($guidHash, $urlHash);

            $entry = new Entry(
                $feed,
                $parsedEntry->guid,
                $parsedEntry->url === null ? null : mb_substr($parsedEntry->url, 0, self::URL_MAX),
                mb_substr($parsedEntry->title, 0, self::TITLE_MAX),
                $context->fetchedAt,
                EntryEffectiveDate::for($parsedEntry->publishedAt, $context),
                $urlHash,
            );
            $entry->setAuthor(
                $parsedEntry->author === null ? null : mb_substr($parsedEntry->author, 0, self::AUTHOR_MAX),
            );
            $entry->setSummary(EntrySnippet::from($parsedEntry->summary ?? $parsedEntry->contentHtml));
            $entry->setContentHtml($this->sanitizer->sanitize($parsedEntry->contentHtml));
            $entry->setPublishedAt($parsedEntry->publishedAt);
            $this->applyImage($entry, $parsedEntry->image);

            $this->em->persist($entry);
            $created[] = $entry;
        }

        return $created;
    }

    /**
     * Fill in the image on entries ingested before the feed's image was
     * persisted (#148), matching by guid hash against a fresh parse.
     *
     * Only entries whose image is currently NULL are touched — a feed that
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
            if ($parsedEntry->image === null) {
                continue;
            }
            $entry = $existing[self::guidHash($parsedEntry->guid)] ?? null;
            if ($entry === null || $entry->getImageUrl() !== null) {
                continue;
            }
            $url = $this->persistableImageUrl($parsedEntry->image);
            if ($url === null) {
                continue;
            }
            $entry->setImage($url, $parsedEntry->image->width, $parsedEntry->image->height);
            $updated++;
        }

        return $updated;
    }

    private function updateFeedMetadata(Feed $feed, ParsedFeed $parsed): void
    {
        if ($parsed->title !== null) {
            $feed->setTitle(mb_substr($parsed->title, 0, self::FEED_TITLE_MAX));
        }
        if ($parsed->siteUrl !== null) {
            $feed->setSiteUrl(mb_substr($parsed->siteUrl, 0, self::URL_MAX));
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

    /**
     * Sets all three image columns together so a rejected image (null, an
     * unusable scheme, or a URL over the column limit) never leaves a stale
     * width/height behind. ParsedImage already treats a missing image as a
     * first-class case the layout falls back from, so losing the image here
     * is preferable to persisting something guaranteed to render broken
     * forever.
     */
    private function applyImage(Entry $entry, ?ParsedImage $image): void
    {
        if ($image === null) {
            $entry->setImage(null, null, null);

            return;
        }

        $url = $this->persistableImageUrl($image);
        if ($url === null) {
            $entry->setImage(null, null, null);

            return;
        }

        $entry->setImage($url, $image->width, $image->height);
    }

    /**
     * HttpsImageUrl owns the rule; this keeps the ParsedImage-shaped call the
     * ingest path reads better with.
     */
    private function persistableImageUrl(ParsedImage $image): ?string
    {
        return HttpsImageUrl::orNull($image->url);
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
     * @param list<ParsedEntry> $entries
     *
     * @return list<string> the url hashes of the entries that have a URL —
     *                      url-less items dedupe on GUID alone, so they add no
     *                      hash here
     */
    private function urlHashesOf(array $entries): array
    {
        $hashes = [];
        foreach ($entries as $entry) {
            $urlHash = $this->urlHash($entry->url);
            if ($urlHash !== null) {
                $hashes[] = $urlHash;
            }
        }

        return $hashes;
    }

    private function urlHash(?string $url): ?string
    {
        return $this->urlNormalizer->hash($url);
    }

    private static function guidHash(string $guid): string
    {
        return hash('sha256', $guid);
    }
}

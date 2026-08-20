<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\ParsedImage;
use App\Service\Sanitize\EntrySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against existing
 * guidHashes (and within the batch), sanitizes content, truncates to column
 * limits, and refreshes feed metadata. Caller flushes.
 */
final class EntryIngestor
{
    private const int TITLE_MAX = 1024;
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;
    private const int FEED_TITLE_MAX = 512;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
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

        $hashes = $this->guidHashesOf($parsed->entries);
        $seen = array_fill_keys($this->entryRepository->findExistingGuidHashes($feed, $hashes), true);

        $created = [];
        foreach ($parsed->entries as $parsedEntry) {
            $hash = self::guidHash($parsedEntry->guid);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $entry = new Entry(
                $feed,
                $parsedEntry->guid,
                $parsedEntry->url === null ? null : mb_substr($parsedEntry->url, 0, self::URL_MAX),
                mb_substr($parsedEntry->title, 0, self::TITLE_MAX),
                $context->fetchedAt,
                EntryEffectiveDate::for($parsedEntry->publishedAt, $context),
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
            $feed->setDescription($parsed->description);
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
     * Rejects a parsed image URL in the two ways it can be unusable rather
     * than trying to repair it:
     *
     * - Scheme: the reader SPA is served over https, so an http:// image is
     *   mixed-content-blocked and never renders — dead weight, silently. A
     *   `//host/path` protocol-relative src is unambiguous and upgraded to
     *   https:// before the check; a `data:` URI or a site-relative path
     *   (`/img/x.jpg`) has no scheme to upgrade and no base URL is plumbed
     *   this deep to resolve one against, so it is dropped rather than
     *   guessed at.
     * - Length: a URL over URL_MAX is not truncated. Cutting it at exactly
     *   URL_MAX characters does not shorten a valid URL, it produces a
     *   different, broken one that will 404 in the reader.
     */
    private function persistableImageUrl(ParsedImage $image): ?string
    {
        $url = str_starts_with($image->url, '//') ? 'https:' . $image->url : $image->url;

        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        return mb_strlen($url) > self::URL_MAX ? null : $url;
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

    private static function guidHash(string $guid): string
    {
        return hash('sha256', $guid);
    }
}

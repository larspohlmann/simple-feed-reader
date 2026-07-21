<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Parser\ParsedFeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against existing
 * guidHashes (and within the batch), sanitizes content, truncates to column
 * limits, and refreshes feed metadata. Caller flushes.
 */
final class EntryIngestor
{
    private const TITLE_MAX = 1024;
    private const AUTHOR_MAX = 255;
    private const URL_MAX = 2048;
    private const FEED_TITLE_MAX = 512;
    private const SUMMARY_MAX = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function ingest(Feed $feed, ParsedFeed $parsed): int
    {
        $this->updateFeedMetadata($feed, $parsed);

        if ($parsed->entries === []) {
            return 0;
        }

        $hashes = array_map(
            static fn ($entry): string => hash('sha256', $entry->guid),
            $parsed->entries,
        );
        $seen = array_fill_keys($this->entryRepository->findExistingGuidHashes($feed, $hashes), true);

        $created = 0;
        foreach ($parsed->entries as $parsedEntry) {
            $hash = hash('sha256', $parsedEntry->guid);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $entry = new Entry(
                $feed,
                $parsedEntry->guid,
                $parsedEntry->url === null ? null : mb_substr($parsedEntry->url, 0, self::URL_MAX),
                mb_substr($parsedEntry->title, 0, self::TITLE_MAX),
                $this->clock->now(),
            );
            $entry->setAuthor(
                $parsedEntry->author === null ? null : mb_substr($parsedEntry->author, 0, self::AUTHOR_MAX),
            );
            $entry->setSummary($this->summarize($parsedEntry->summary));
            $entry->setContentHtml($this->sanitizer->sanitize($parsedEntry->contentHtml));
            $entry->setPublishedAt($parsedEntry->publishedAt);

            $this->em->persist($entry);
            $created++;
        }

        return $created;
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
     * Produces PLAIN TEXT, not HTML. strip_tags removes real tags, then
     * html_entity_decode turns "&lt;script&gt;" back into literal "<script>"
     * characters — so the result may contain <, > and & and has NOT been
     * through EntrySanitizer. Render it as text only; never with |raw,
     * innerHTML, or dangerouslySetInnerHTML.
     */
    private function summarize(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::SUMMARY_MAX);
    }
}

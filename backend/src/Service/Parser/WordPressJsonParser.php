<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

/**
 * Turns a WordPress `wp/v2/posts?_embed` JSON array into a ParsedFeed. The
 * reusable core shared by the refresh strategy (WpJsonBodyParser) and the
 * subscribe-dialog preview, mirroring how FeedParser and HtmlItemExtractor are
 * used directly by both pipelines.
 *
 * The posts endpoint carries no site name, so ParsedFeed::title is null; the
 * discovery candidate supplies a readable title from the page instead.
 */
final readonly class WordPressJsonParser
{
    public function parse(string $body): ParsedFeed
    {
        /** @var mixed $posts */
        $posts = json_decode(trim($body), true);
        if (!\is_array($posts) || !array_is_list($posts)) {
            // A non-array body is a WordPress error object or a broken payload;
            // an empty list is a legitimately empty feed and falls through.
            throw new FeedParseException('WordPress REST body is not a post array');
        }

        $entries = [];
        foreach ($posts as $post) {
            if (\is_array($post)) {
                /** @var array<string, mixed> $post */
                $entries[] = $this->entry($post);
            }
        }

        return new ParsedFeed(null, null, null, $entries);
    }

    /** @param array<string, mixed> $post */
    private function entry(array $post): ParsedEntry
    {
        return new ParsedEntry(
            guid: $this->guid($post),
            url: $this->stringOrNull($post['link'] ?? null),
            title: $this->plainTitle($this->rendered($post, 'title')),
            author: $this->author($post),
            summary: $this->rendered($post, 'excerpt'),
            contentHtml: $this->rendered($post, 'content'),
            publishedAt: $this->publishedAt($post),
            image: $this->image($post),
        );
    }

    /** @param array<string, mixed> $post */
    private function guid(array $post): string
    {
        $guid = $this->stringOrNull($this->rendered($post, 'guid'))
            ?? $this->stringOrNull($post['id'] ?? null)
            ?? $this->stringOrNull($post['link'] ?? null);

        if (null === $guid) {
            throw new FeedParseException('WordPress post has no id, guid or link');
        }

        return $guid;
    }

    /**
     * The `.rendered` sub-value WordPress wraps title/content/excerpt/guid in.
     *
     * @param array<string, mixed> $post
     */
    private function rendered(array $post, string $field): ?string
    {
        $value = $post[$field] ?? null;

        return \is_array($value) ? $this->stringOrNull($value['rendered'] ?? null) : null;
    }

    private function plainTitle(?string $rendered): string
    {
        if (null === $rendered) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($rendered), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $post */
    private function author(array $post): ?string
    {
        $embedded = $post['_embedded'] ?? null;
        if (!\is_array($embedded)) {
            return null;
        }

        $authorList = $embedded['author'] ?? null;
        if (!\is_array($authorList)) {
            return null;
        }

        $firstAuthor = $authorList[0] ?? null;
        if (!\is_array($firstAuthor)) {
            return null;
        }

        $author = $firstAuthor['name'] ?? null;

        return $this->stringOrNull($author);
    }

    /** @param array<string, mixed> $post */
    private function publishedAt(array $post): ?\DateTimeImmutable
    {
        $dateGmt = $this->stringOrNull($post['date_gmt'] ?? null);
        if (null === $dateGmt) {
            return null;
        }

        try {
            // date_gmt is UTC wall-clock with no offset designator; pin the zone
            // so it is never read in the server's local time (naive-UTC gotcha).
            return new \DateTimeImmutable($dateGmt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $post */
    private function image(array $post): ?ParsedImage
    {
        $embedded = $post['_embedded'] ?? null;
        if (!\is_array($embedded)) {
            return null;
        }

        $mediaList = $embedded['wp:featuredmedia'] ?? null;
        if (!\is_array($mediaList)) {
            return null;
        }

        $media = $mediaList[0] ?? null;
        if (!\is_array($media)) {
            return null;
        }

        $url = $this->stringOrNull($media['source_url'] ?? null);
        if (null === $url) {
            return null;
        }

        $mediaDetails = $media['media_details'] ?? null;
        $details = \is_array($mediaDetails) ? $mediaDetails : [];
        $width = $this->intOrNull($details['width'] ?? null);
        $height = $this->intOrNull($details['height'] ?? null);

        return new ParsedImage($url, $width, $height);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (\is_string($value) && '' !== trim($value)) {
            return $value;
        }

        return \is_int($value) ? (string) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}

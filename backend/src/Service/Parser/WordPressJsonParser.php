<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Text\PlainText;

/**
 * Turns a WordPress `wp/v2/posts` JSON array (`_fields`-pruned, no `_embed`)
 * into a ParsedFeed. The reusable core shared by the refresh strategy
 * (WpJsonBodyParser) and the subscribe-dialog preview, mirroring how
 * FeedParser and HtmlItemExtractor are used directly by both pipelines.
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

        return new ParsedFeed(null, null, null, null, $entries);
    }

    /** @param array<string, mixed> $post */
    private function entry(array $post): ParsedEntry
    {
        return new ParsedEntry(
            guid: $this->guid($post),
            url: $this->stringOrNull($post['link'] ?? null),
            title: PlainText::from($this->rendered($post, 'title')) ?? '(untitled)',
            // No author NAME without _embed (only an id), and _embed is too
            // heavy to request; bylines usually live in content.rendered anyway.
            author: null,
            summary: $this->rendered($post, 'excerpt'),
            contentHtml: $this->rendered($post, 'content'),
            publishedAt: $this->publishedAt($post),
            image: $this->image($post),
        );
    }

    /**
     * The featured image. Jetpack's top-level convenience field is preferred so
     * no `_embed` is needed; a Jetpack-less site carries its picture inline in
     * the body instead, so the content lead image is the fallback — the same
     * precedence FeedParser applies to RSS/Atom items via ItemImageExtractor.
     * Absent on both → null (the reader still extracts a hero on open).
     * Dimensions are unknown from either source.
     *
     * @param array<string, mixed> $post
     */
    private function image(array $post): ?DeclaredImage
    {
        $jetpackUrl = $this->stringOrNull($post['jetpack_featured_media_url'] ?? null);
        if ($jetpackUrl !== null) {
            return new DeclaredImage($jetpackUrl);
        }

        return ItemImageExtractor::fromHtml($this->rendered($post, 'content'))
            ?? ItemImageExtractor::fromHtml($this->rendered($post, 'excerpt'));
    }

    /** @param array<string, mixed> $post */
    private function guid(array $post): string
    {
        $guid = $this->rendered($post, 'guid')
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

    private function stringOrNull(mixed $value): ?string
    {
        if (\is_string($value)) {
            $trimmed = trim($value);

            return '' === $trimmed ? null : $trimmed;
        }

        return \is_int($value) ? (string) $value : null;
    }
}

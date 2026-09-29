<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Text\Support\PlainText;

/**
 * Turns a WordPress `wp/v2/posts` JSON array (`_fields`-pruned, no `_embed`) into a ParsedFeedModel, for the refresh
 * strategy and the subscribe preview alike. The endpoint carries no site name, so the feed title is null.
 */
final readonly class WordPressJsonParser
{
    public function __construct(private ItemImageExtractor $imageExtractor)
    {
    }

    public function parse(string $body): ParsedFeedModel
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

        return new ParsedFeedModel(null, null, null, null, $entries);
    }

    /** @param array<string, mixed> $post */
    private function entry(array $post): ParsedEntryModel
    {
        $image = $this->image($post);

        return new ParsedEntryModel(
            guid: $this->guid($post),
            url: $this->stringOrNull($post['link'] ?? null),
            title: PlainText::from($this->rendered($post, 'title')) ?? '(untitled)',
            // No author NAME without _embed (only an id), and _embed is too
            // heavy to request; bylines usually live in content.rendered anyway.
            author: null,
            summary: $this->rendered($post, 'excerpt'),
            contentHtml: $this->rendered($post, 'content'),
            publishedAt: $this->publishedAt($post),
            media: new ParsedEntryMediaModel($image),
        );
    }

    /**
     * Jetpack's top-level featured-image field needs no `_embed`; without Jetpack the content's first image stands in.
     * Neither declares dimensions.
     *
     * @param array<string, mixed> $post
     */
    private function image(array $post): ?DeclaredImageModel
    {
        $jetpackUrl = $this->stringOrNull($post['jetpack_featured_media_url'] ?? null);
        if ($jetpackUrl !== null) {
            return new DeclaredImageModel($jetpackUrl);
        }

        return $this->imageExtractor->fromHtml($this->rendered($post, 'content'))
            ?? $this->imageExtractor->fromHtml($this->rendered($post, 'excerpt'));
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

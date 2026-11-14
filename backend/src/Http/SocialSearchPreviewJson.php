<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Foundation\Text\Support\PlainText;

final readonly class SocialSearchPreviewJson
{
    private const int SHOWN_POSTS = 10;

    /** @return array{count: int, posts: list<array{url: ?string, author: ?string, authorAvatarUrl: ?string, text: string, publishedAt: ?string}>} */
    public function preview(TimelinePageModel $page): array
    {
        $entries = $page->parsed->entries;

        return [
            'count' => \count($entries),
            'posts' => array_map(self::post(...), \array_slice($entries, 0, self::SHOWN_POSTS)),
        ];
    }

    /** @return array{url: ?string, author: ?string, authorAvatarUrl: ?string, text: string, publishedAt: ?string} */
    private static function post(ParsedEntryModel $entry): array
    {
        return [
            'url' => $entry->url,
            'author' => $entry->author,
            'authorAvatarUrl' => $entry->authorAvatarUrl,
            'text' => PlainText::fromHtmlBlocks($entry->contentHtml) ?? $entry->headline->text,
            'publishedAt' => $entry->publishedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}

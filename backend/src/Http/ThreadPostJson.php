<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Social\Model\ThreadPostModel;

/**
 * @phpstan-import-type PlacedLinkCardRow from LinkCardJson
 * @phpstan-type ThreadPostRow array{
 *   url: string|null, publishedAt: string|null, html: string, text: string,
 *   media: list<array<string, string|int|true>>, linkCard: PlacedLinkCardRow|null
 * }
 */
final readonly class ThreadPostJson
{
    public function __construct(
        private LinkCardJson $linkCardJson,
    ) {
    }

    /** @return ThreadPostRow */
    public function of(ThreadPostModel $post): array
    {
        return [
            'url' => $post->url,
            'publishedAt' => $post->publishedAt?->format(\DateTimeInterface::ATOM),
            'html' => $post->html,
            'text' => EntrySnippet::from($post->html) ?? '',
            'media' => EntryMedia::toJsonList($post->media),
            'linkCard' => $post->linkCard === null ? null : $this->linkCardJson->placed($post->linkCard),
        ];
    }
}

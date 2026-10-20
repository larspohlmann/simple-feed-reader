<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Social\Model\ThreadPostModel;

/**
 * @phpstan-import-type LinkCardRow from LinkCardJson
 * @phpstan-type ThreadPostRow array{
 *   url: string|null, publishedAt: string|null, html: string, text: string,
 *   media: list<array<string, string|int|true>>, linkCard: LinkCardRow|null
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
            'linkCard' => $this->linkCardJson->ofOrNull($post->linkCard),
        ];
    }
}

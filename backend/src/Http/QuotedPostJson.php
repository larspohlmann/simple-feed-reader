<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Entity\QuotedPostContent;

/**
 * @phpstan-type QuoteRow array{
 *   url: string, authorName: string, authorHandle: string, authorAvatarUrl: string|null, postedAt: string|null,
 *   text: string, media: list<array<string, string|int|true>>,
 *   nestedQuote: array{url: string, handle: string|null}|null
 * }
 */
final class QuotedPostJson
{
    /** @return QuoteRow|null */
    public static function ofOrNull(?QuotedPostContent $quote): ?array
    {
        if ($quote === null) {
            return null;
        }
        $nested = $quote->nestedQuote;

        return [
            'url' => $quote->url,
            'authorName' => $quote->authorName,
            'authorHandle' => $quote->authorHandle,
            'authorAvatarUrl' => $quote->authorAvatarUrl,
            'postedAt' => $quote->postedAt?->format(\DateTimeInterface::ATOM),
            'text' => $quote->text,
            'media' => EntryMedia::toJsonList($quote->media),
            'nestedQuote' => $nested === null ? null : ['url' => $nested->url, 'handle' => $nested->handle],
        ];
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\ExtractionResult;

final class ReaderJson
{
    /**
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null,
     *   leadImage: string|null, extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string}
     */
    public static function one(ExtractionResult $r, \DateTimeImmutable $now): array
    {
        if (!$r->ok) {
            return ['status' => 'failed', 'url' => $r->url, 'reason' => (string) $r->reason];
        }

        return [
            'status' => 'ok',
            'url' => (string) $r->url,
            'title' => (string) $r->title,
            'byline' => $r->byline,
            'siteName' => $r->siteName,
            'contentHtml' => (string) $r->contentHtml,
            'excerpt' => $r->excerpt,
            'leadImage' => $r->image,
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\ExtractionResult;
use App\Service\Image\DeclaredImage;

final class ReaderJson
{
    /**
     * The reader body carries its own lead picture (#681), so the only hero the
     * response still declares is the original view's — the feed image, shown when
     * the feed body has none. The field rides on both branches: a failed
     * extraction has no reader body, but the original view still has its hero.
     *
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null,
     *   extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null}
     */
    public static function one(ExtractionResult $r, ?DeclaredImage $originalHero, \DateTimeImmutable $now): array
    {
        if (!$r->ok) {
            return [
                'status' => 'failed',
                'url' => $r->url,
                'reason' => (string) $r->reason,
                'originalHero' => self::hero($originalHero),
            ];
        }

        return [
            'status' => 'ok',
            'url' => (string) $r->url,
            'title' => (string) $r->title,
            'byline' => $r->byline,
            'siteName' => $r->siteName,
            'contentHtml' => (string) $r->contentHtml,
            'excerpt' => $r->excerpt,
            'originalHero' => self::hero($originalHero),
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private static function hero(?DeclaredImage $hero): ?array
    {
        return $hero === null
            ? null
            : ['url' => $hero->url, 'width' => $hero->width, 'height' => $hero->height];
    }
}

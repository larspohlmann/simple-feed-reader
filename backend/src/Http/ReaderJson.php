<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\ExtractionResult;
use App\Service\Reader\HeroImage;
use App\Service\Reader\ReaderHeroes;

final class ReaderJson
{
    /**
     * Both heroes ride on both branches. A failed extraction has no extracted
     * body to lead, so its reader hero is always null — but the field is there,
     * so any client reads the same two fields whatever the status (#592).
     *
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null,
     *   readerHero: array{url: string, width: int|null, height: int|null}|null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null,
     *   extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string,
     *   readerHero: null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null}
     */
    public static function one(ExtractionResult $r, ReaderHeroes $heroes, \DateTimeImmutable $now): array
    {
        if (!$r->ok) {
            return [
                'status' => 'failed',
                'url' => $r->url,
                'reason' => (string) $r->reason,
                'readerHero' => null,
                'originalHero' => self::hero($heroes->originalHero),
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
            'readerHero' => self::hero($heroes->readerHero),
            'originalHero' => self::hero($heroes->originalHero),
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private static function hero(?HeroImage $hero): ?array
    {
        return $hero === null
            ? null
            : ['url' => $hero->url, 'width' => $hero->width, 'height' => $hero->height];
    }
}

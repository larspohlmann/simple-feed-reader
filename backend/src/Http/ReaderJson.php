<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Reader\Model\ExtractionResultModel;

final class ReaderJson
{
    /**
     * The reader body carries its own lead picture (#681), so the only hero the
     * response still declares is the original view's — the feed image, shown when
     * the feed body has none. The field rides on both branches: a failed
     * extraction has no reader body, but the original view still has its hero.
     *
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null, paywalled: bool,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null,
     *   extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string, detail: string|null,
     *   originalHero: array{url: string, width: int|null, height: int|null}|null}
     */
    public static function one(
        ExtractionResultModel $result,
        ?DeclaredImageModel $originalHero,
        \DateTimeImmutable $now,
    ): array {
        if ($result->reason !== null) {
            return [
                'status' => 'failed',
                'url' => $result->url,
                'reason' => $result->reason->value,
                'detail' => $result->detail,
                'originalHero' => self::hero($originalHero),
            ];
        }

        return [
            'status' => 'ok',
            'url' => (string) $result->url,
            'title' => (string) $result->title,
            'byline' => $result->byline,
            'siteName' => $result->siteName,
            'contentHtml' => (string) $result->contentHtml,
            'excerpt' => $result->excerpt,
            'paywalled' => $result->paywalled,
            'originalHero' => self::hero($originalHero),
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private static function hero(?DeclaredImageModel $hero): ?array
    {
        return $hero === null
            ? null
            : ['url' => $hero->url, 'width' => $hero->width, 'height' => $hero->height];
    }
}

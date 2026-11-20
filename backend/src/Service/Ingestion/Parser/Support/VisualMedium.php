<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Parser\Support;

use App\Entity\EntryMedium;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Ingestion\Parser\Model\FeedMediaKind;
use App\Service\Ingestion\Parser\Model\VisualMediaKind;

final class VisualMedium
{
    public static function at(string $url, ?string $posterUrl = null): ?EntryMedium
    {
        return self::buildMedium($url, FeedMediaClassifier::classifyUrl($url), $posterUrl);
    }

    public static function servedAs(string $url, string $mimeType): ?EntryMedium
    {
        return self::buildMedium($url, FeedMediaClassifier::classifyMimeType($mimeType), null);
    }

    private static function buildMedium(string $url, FeedMediaKind $kind, ?string $posterUrl): ?EntryMedium
    {
        $secure = HttpsImageUrl::parseUrl($url);
        if ($secure === null) {
            return null;
        }

        return match ($kind) {
            FeedMediaKind::Image => new EntryMedium($secure, VisualMediaKind::Image->value),
            FeedMediaKind::Video => new EntryMedium(
                $secure,
                VisualMediaKind::Video->value,
                previewImageUrl: HttpsImageUrl::parseUrl($posterUrl),
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}

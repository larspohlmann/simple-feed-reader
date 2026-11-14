<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryMedium;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\Support\HttpsImageUrl;

final class VisualMedium
{
    public static function at(string $url, ?string $posterUrl = null): ?EntryMedium
    {
        return self::of($url, FeedMediaClassifier::kindOfUrl($url), $posterUrl);
    }

    public static function servedAs(string $url, string $mimeType): ?EntryMedium
    {
        return self::of($url, FeedMediaClassifier::kindOfMimeType($mimeType), null);
    }

    private static function of(string $url, FeedMediaKind $kind, ?string $posterUrl): ?EntryMedium
    {
        $secure = HttpsImageUrl::orNull($url);
        if ($secure === null) {
            return null;
        }

        return match ($kind) {
            FeedMediaKind::Image => new EntryMedium($secure, VisualMediaKind::Image->value),
            FeedMediaKind::Video => new EntryMedium(
                $secure,
                VisualMediaKind::Video->value,
                previewImageUrl: HttpsImageUrl::orNull($posterUrl),
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}

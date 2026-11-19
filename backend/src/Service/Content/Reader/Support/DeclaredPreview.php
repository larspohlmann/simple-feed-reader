<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Support;

use App\Enum\FeedLanguage;
use App\Service\Content\Reader\Model\PagePreviewModel;
use App\Service\Foundation\Html\Support\MetaTags;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Ingestion\Scraper\Support\TextNormalizer;
use App\Service\Outbound\Fetch\Pass\PageUrls;
use App\Service\Outbound\Fetch\Support\LandingSite;
use Dom\HTMLDocument;

/** What a page's head says it is: its title, description, image and site, and the language it states. */
final class DeclaredPreview
{
    public static function of(HTMLDocument $document, string $addressUrl, string $finalUrl): PagePreviewModel
    {
        $meta = MetaTags::contentByKey($document);

        return new PagePreviewModel(
            $addressUrl,
            self::declared($meta, 'og:title', 'twitter:title') ?: TextNormalizer::normalize($document->title),
            self::declared($meta, 'og:description', 'twitter:description', 'description') ?: null,
            self::image($meta, $finalUrl),
            self::declared($meta, 'og:site_name') ?: LandingSite::pickHost($addressUrl, $finalUrl),
            self::statedLanguage($document, $meta),
        );
    }

    /** @param array<string, string> $meta */
    private static function statedLanguage(HTMLDocument $document, array $meta): ?FeedLanguage
    {
        $htmlLang = trim((string) $document->documentElement?->getAttribute('lang'));

        return FeedLanguage::fromTag($htmlLang !== '' ? $htmlLang : $meta['og:locale'] ?? null);
    }

    /** @param array<string, string> $meta */
    private static function image(array $meta, string $finalUrl): ?string
    {
        $declared = self::declared($meta, 'og:image', 'twitter:image');

        return HttpsImageUrl::orNullUpgrading(new PageUrls($finalUrl)->httpUrl($declared));
    }

    /**
     * @param array<string, string> $meta
     *
     * @return string the first of these keys' contents that holds text, or ''
     */
    private static function declared(array $meta, string ...$keys): string
    {
        foreach ($keys as $key) {
            $normalized = TextNormalizer::normalize($meta[$key] ?? '');
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function __construct()
    {
    }
}

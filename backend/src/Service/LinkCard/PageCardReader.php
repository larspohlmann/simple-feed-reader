<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Fetch\Support\HostKey;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaTags;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Support\DeclaredPageMedium;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\HttpsImageUrl;

/** Reads the card a page declares for itself, and the picture or video file it is or declares. */
final readonly class PageCardReader
{
    public function __construct(private HtmlPageFetcher $pages)
    {
    }

    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel
    {
        $page = $this->pages->fetchHead($sharedUrl);
        $file = self::fileMedium($sharedUrl, $page->contentType);
        if ($file !== null) {
            return new PageCardModel(null, $file);
        }
        $document = HtmlDocumentParser::parseOrEmpty($page->html);
        $meta = MetaTags::contentByKey($document);

        return new PageCardModel(
            StorableLinkCard::of(new LinkCardContent(
                $sharedUrl,
                self::declared($meta, 'og:title', 'twitter:title') ?: TextNormalizer::normalize($document->title),
                self::declared($meta, 'og:description', 'twitter:description', 'description') ?: null,
                self::image($meta, $page->finalUrl),
                self::declared($meta, 'og:site_name') ?: HostKey::forUrl($sharedUrl),
            )),
            DeclaredPageMedium::of($meta),
        );
    }

    private static function fileMedium(string $sharedUrl, ?string $contentType): ?EntryMedium
    {
        $type = strtolower((string) $contentType);
        $kind = match (true) {
            str_starts_with($type, 'image/') => VisualMediaKind::Image,
            str_starts_with($type, 'video/') => VisualMediaKind::Video,
            default => null,
        };
        $url = HttpsImageUrl::orNull($sharedUrl);

        return $kind === null || $url === null ? null : new EntryMedium($url, $kind->value);
    }

    /** @param array<string, string> $meta */
    private static function image(array $meta, string $finalUrl): ?string
    {
        $declared = self::declared($meta, 'og:image', 'twitter:image');

        return HttpsImageUrl::orNullUpgrading((new PageUrls($finalUrl))->httpUrl($declared));
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
}

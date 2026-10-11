<?php

declare(strict_types=1);

namespace App\Service\LinkCard\PageCardReader;

use App\Entity\LinkCardContent;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Fetch\Support\HostKey;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaTags;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Support\DeclaredPageMedium;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Parser\Support\VisualMedium;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\PageHeadFetcher;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\HttpsImageUrl;

/** Reads the card a page declares for itself, and the picture or video file it is or declares. */
final readonly class PageCardReader implements PageCardReaderInterface
{
    public function __construct(private PageHeadFetcher $heads)
    {
    }

    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel
    {
        $page = $this->heads->fetch($sharedUrl);
        if ($page->fileType !== null) {
            return new PageCardModel(null, VisualMedium::servedAs($sharedUrl, $page->fileType));
        }
        $document = HtmlDocumentParser::parseOrEmpty($page->head);
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

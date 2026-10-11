<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\LinkCardContent;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Fetch\Support\HostKey;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaName;
use App\Service\Html\Support\MetaProperty;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\HttpsImageUrl;
use Dom\HTMLDocument;

/** Reads the card a page declares for itself: its Open Graph or Twitter title, description, image and site. */
final readonly class PageCardReader
{
    public function __construct(private HtmlPageFetcher $pages)
    {
    }

    /** @throws PageFetchException */
    public function read(string $sharedUrl): ?LinkCardContent
    {
        $page = $this->pages->fetchHead($sharedUrl);
        $document = HtmlDocumentParser::parseOrEmpty($page->html);

        return StorableLinkCard::of(new LinkCardContent(
            $sharedUrl,
            $this->title($document),
            $this->description($document),
            $this->image($document, $page->finalUrl),
            $this->siteName($document, $sharedUrl),
        ));
    }

    private function title(HTMLDocument $document): string
    {
        return $this->firstFilled(
            $this->meta($document, 'og:title'),
            $this->meta($document, 'twitter:title'),
            $document->title,
        );
    }

    private function description(HTMLDocument $document): ?string
    {
        $description = $this->firstFilled(
            $this->meta($document, 'og:description'),
            $this->meta($document, 'twitter:description'),
            MetaName::content($document, 'description'),
        );

        return $description === '' ? null : $description;
    }

    private function image(HTMLDocument $document, string $finalUrl): ?string
    {
        $declared = $this->firstFilled($this->meta($document, 'og:image'), $this->meta($document, 'twitter:image'));

        return HttpsImageUrl::orNullUpgrading((new PageUrls($finalUrl))->httpUrl($declared));
    }

    private function siteName(HTMLDocument $document, string $sharedUrl): string
    {
        return $this->firstFilled($this->meta($document, 'og:site_name'), HostKey::forUrl($sharedUrl));
    }

    private function meta(HTMLDocument $document, string $key): string
    {
        return MetaProperty::content($document, $key) ?: MetaName::content($document, $key);
    }

    private function firstFilled(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $normalized = TextNormalizer::normalize($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\LinkCardContent;
use App\Service\Fetch\Support\HostKey;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaName;
use App\Service\Html\Support\MetaProperty;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Text\Support\WordBoundary;
use App\Service\Url\Support\HttpsImageUrl;
use App\Service\Url\Support\ResolvedUrl;
use Dom\HTMLDocument;

/** Reads the card a page declares for itself: its Open Graph or Twitter title, description, image and site. */
final readonly class PageCardReader
{
    private const int TITLE_LIMIT = 500;
    private const int DESCRIPTION_LIMIT = 300;
    private const int SITE_NAME_LIMIT = 255;

    public function __construct(private HtmlPageFetcher $pages)
    {
    }

    /** @throws PageFetchException */
    public function read(string $sharedUrl): ?LinkCardContent
    {
        $page = $this->pages->fetch($sharedUrl);
        $document = HtmlDocumentParser::parseOrEmpty($page->html);

        $title = $this->title($document);
        if ($title === '') {
            return null;
        }

        return new LinkCardContent(
            $sharedUrl,
            mb_substr($title, 0, self::TITLE_LIMIT),
            $this->description($document),
            $this->image($document, $page->finalUrl),
            $this->siteName($document, $sharedUrl),
        );
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
        if ($description === '') {
            return null;
        }

        return mb_strlen($description) <= self::DESCRIPTION_LIMIT
            ? $description
            : WordBoundary::cut($description, self::DESCRIPTION_LIMIT - 1) . '…';
    }

    private function image(HTMLDocument $document, string $finalUrl): ?string
    {
        $declared = trim($this->meta($document, 'og:image') ?: $this->meta($document, 'twitter:image'));

        return $declared === '' ? null : HttpsImageUrl::orNullUpgrading(ResolvedUrl::against($finalUrl, $declared));
    }

    private function siteName(HTMLDocument $document, string $sharedUrl): string
    {
        $declared = $this->firstFilled($this->meta($document, 'og:site_name'), HostKey::forUrl($sharedUrl));

        return mb_substr($declared, 0, self::SITE_NAME_LIMIT);
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

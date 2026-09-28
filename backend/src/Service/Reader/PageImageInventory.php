<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Srcset;
use Dom\HTMLDocument;

/**
 * The URLs a normalised page draws, read before readability consumes the document (#684). It tells
 * ReaderLeadImage whether the page draws the lead or og:image is a meta-only share render; fingerprints are
 * computed lazily in draws(), stopping at the first match.
 */
final readonly class PageImageInventory
{
    /** @param list<string> $renderedUrls */
    private function __construct(private array $renderedUrls)
    {
    }

    public static function fromDocument(HTMLDocument $page): self
    {
        return new self(self::renderedUrls($page));
    }

    public function draws(ImageIdentity $lead): bool
    {
        foreach ($this->renderedUrls as $url) {
            if ($lead->matches(ImageIdentity::fromUrl($url))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every URL the page draws, in document order */
    private static function renderedUrls(HTMLDocument $page): array
    {
        $urls = [];
        foreach ($page->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src') ?? '');
            if ($source !== '') {
                $urls[] = $source;
            }
        }
        foreach ($page->getElementsByTagName('source') as $source) {
            $first = Srcset::firstUrl($source->getAttribute('srcset'));
            if ($first !== null) {
                $urls[] = $first;
            }
        }

        return $urls;
    }
}

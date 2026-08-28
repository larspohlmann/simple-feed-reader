<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Srcset;
use Dom\HTMLDocument;

/**
 * The URLs a normalised page draws, scanned once from the FetchedPageNormalizer
 * document. There LazyImageSources has already promoted every lazy source to a
 * plain `src` and flattened each <picture> to its <img>, so the scan is a plain
 * `img@src` + `source@srcset` read — no `data-*` digging, which LazyImageSources
 * owns (#684).
 *
 * It answers one question for ReaderLeadImage: does the page actually draw the
 * lead photo, or is the og:image a meta-only share-render? A miss only skips the
 * restore, so a URL the scan does not carry is safe by design.
 *
 * The ImageIdentity fingerprint of each URL is computed lazily inside draws(),
 * which stops at the first match: the gate is consulted only on the minority of
 * restores where the body already holds another picture, and an image-heavy page
 * (thumbnail rails, ad units) carries many URLs that would otherwise be
 * fingerprinted on every extraction for nothing.
 */
final readonly class PageImageInventory
{
    /** @param list<string> $renderedUrls */
    private function __construct(private array $renderedUrls)
    {
    }

    public static function fromDocument(?HTMLDocument $page): self
    {
        if ($page === null) {
            return new self([]);
        }

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

    /**
     * @return list<string> every URL the page draws, in document order
     */
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

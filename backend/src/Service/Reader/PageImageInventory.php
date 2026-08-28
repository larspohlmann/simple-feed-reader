<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Srcset;
use Dom\HTMLDocument;

/**
 * The set of images a normalised page draws, as light ImageIdentity
 * fingerprints, built once from the FetchedPageNormalizer document. There
 * LazyImageSources has already promoted every lazy source to a plain `src` and
 * flattened each <picture> to its <img>, so the scan is a plain `img@src` +
 * `source@srcset` read — no `data-*` digging, which LazyImageSources owns (#684).
 *
 * It answers one question for ReaderLeadImage: does the page actually draw the
 * lead photo, or is the og:image a meta-only share-render? A miss only skips the
 * restore, so a fingerprint the scan does not carry is safe by design.
 */
final readonly class PageImageInventory
{
    /** @param list<ImageIdentity> $drawn */
    private function __construct(private array $drawn)
    {
    }

    public static function fromDocument(?HTMLDocument $page): self
    {
        if ($page === null) {
            return new self([]);
        }

        $drawn = [];
        foreach (self::renderedUrls($page) as $url) {
            $drawn[] = ImageIdentity::fromUrl($url);
        }

        return new self($drawn);
    }

    public function draws(ImageIdentity $lead): bool
    {
        foreach ($this->drawn as $identity) {
            if ($lead->matches($identity)) {
                return true;
            }
        }

        return false;
    }

    /** @return \Generator<string> every URL the page draws, in document order */
    private static function renderedUrls(HTMLDocument $page): \Generator
    {
        foreach ($page->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src') ?? '');
            if ($source !== '') {
                yield $source;
            }
        }
        foreach ($page->getElementsByTagName('source') as $source) {
            $first = Srcset::firstUrl($source->getAttribute('srcset'));
            if ($first !== null) {
                yield $first;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Every figure's (image src, caption text) pair, scanned once from the
 * normalised page before readability discards a header figure as chrome
 * (#684's seam). Answers ReaderLeadImage's other question: when the lead is
 * restored, what caption did its dropped figure carry?
 *
 * Each stored src's ImageIdentity fingerprint is computed lazily inside
 * captionFor(), stopping at the first match — the same rationale as
 * PageImageInventory, since most restores never need this lookup at all.
 */
final readonly class LeadFigureCaptions
{
    /** @param list<array{url: string, caption: string}> $figures */
    private function __construct(private array $figures)
    {
    }

    public static function fromDocument(?HTMLDocument $page): self
    {
        if ($page === null) {
            return new self([]);
        }

        return new self(self::captionedFigures($page));
    }

    public function captionFor(?string $leadUrl): ?string
    {
        if ($leadUrl === null || $leadUrl === '' || preg_match('#^https?://#i', $leadUrl) !== 1) {
            return null;
        }

        $lead = ImageIdentity::fromUrl($leadUrl);
        foreach ($this->figures as $figure) {
            if ($lead->isSameAsset(ImageIdentity::fromUrl($figure['url']))) {
                return $figure['caption'];
            }
        }

        return null;
    }

    /** @return list<array{url: string, caption: string}> */
    private static function captionedFigures(HTMLDocument $page): array
    {
        $figures = [];
        foreach ($page->getElementsByTagName('figure') as $figure) {
            $entry = self::captionedFigure($figure);
            if ($entry !== null) {
                $figures[] = $entry;
            }
        }

        return $figures;
    }

    /** @return ?array{url: string, caption: string} */
    private static function captionedFigure(Element $figure): ?array
    {
        $image = $figure->getElementsByTagName('img')->item(0);
        $source = trim($image?->getAttribute('src') ?? '');
        $captionElement = $figure->getElementsByTagName('figcaption')->item(0);
        if ($source === '' || $captionElement === null) {
            return null;
        }

        $caption = trim((string) preg_replace('/\s+/', ' ', $captionElement->textContent ?? ''));

        return $caption !== '' ? ['url' => $source, 'caption' => $caption] : null;
    }
}

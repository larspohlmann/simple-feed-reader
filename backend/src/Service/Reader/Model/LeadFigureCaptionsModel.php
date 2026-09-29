<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

use App\Service\Text\Support\Whitespace;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Every figure's (image src, caption) pair, read from the normalised page before readability drops a header
 * figure as chrome (#684), so a restored lead gets its caption back. Fingerprints are computed lazily in
 * captionFor(), stopping at the first match.
 */
final readonly class LeadFigureCaptionsModel
{
    /** @param list<array{url: string, caption: string}> $figures */
    private function __construct(private array $figures)
    {
    }

    public static function fromDocument(HTMLDocument $page): self
    {
        return new self(self::captionedFigures($page));
    }

    public function captionFor(?string $leadUrl): ?string
    {
        $leadUrl = AbsoluteHttpUrl::orNull($leadUrl);
        if ($leadUrl === null) {
            return null;
        }

        $lead = ImageIdentityModel::fromUrl($leadUrl);
        foreach ($this->figures as $figure) {
            if ($lead->isSameAsset(ImageIdentityModel::fromUrl($figure['url']))) {
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

        $caption = Whitespace::collapse($captionElement->textContent);

        return $caption !== '' ? ['url' => $source, 'caption' => $caption] : null;
    }
}

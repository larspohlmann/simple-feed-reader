<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Pass\FeedMediaNode;
use App\Service\Parser\Support\DeclaredImages;
use App\Service\Parser\Support\DeclaredRenditions;
use App\Service\Parser\Support\FeedMediaClassifier;
use App\Service\Parser\Support\MediaRssSlot;
use Dom\Element;

/**
 * The images a feed item declares, source by source; FeedItemImageSelector combines them in each format's order.
 * Within Media RSS the widest variant wins, not the first: feeds ship size ladders in ascending order (#148).
 * URLs stay unresolved.
 */
final readonly class ItemImageExtractor
{
    /** Media RSS image, searching <media:group> when nothing is attached directly; its other widths join it. */
    public function fromMedia(CoreElement $item): ?DeclaredImageModel
    {
        $candidates = self::mediaCandidatesIn($item->element);

        foreach ($item->in(FeedNamespace::MediaRss)->children('group') as $group) {
            $candidates = [...$candidates, ...self::mediaCandidatesIn($group)];
        }

        return DeclaredImages::widest($candidates)?->joinedWith(...$candidates);
    }

    public function fromEnclosure(CoreElement $item): ?DeclaredImageModel
    {
        foreach ($item->element->childNodes as $child) {
            if (!$item->isEnclosure($child)) {
                continue;
            }
            $enclosure = new FeedMediaNode($child);
            if ($enclosure->kind() === FeedMediaKind::Image && $enclosure->url() !== '') {
                return $enclosure->toDeclaredImage();
            }
        }

        return null;
    }

    /**
     * Non-standard item-level <image>/<image_big> carrying a `url` attribute: <image_big> wins, then the widest.
     * Requiring the attribute keeps the standard channel <image>, which nests a <url> child, from matching.
     */
    public function fromCustomImageElement(CoreElement $item): ?DeclaredImageModel
    {
        return DeclaredImages::widest(self::customImageCandidates($item->element, 'image_big'))
            ?? DeclaredImages::widest(self::customImageCandidates($item->element, 'image'));
    }

    /** First non-beacon <img src="…"> in a fragment of HTML, with the dimensions and renditions it declares. */
    public function fromHtml(?string $html): ?DeclaredImageModel
    {
        if ($html === null || stripos($html, '<img') === false) {
            return null;
        }
        $document = HtmlDocumentParser::parseOrEmpty($html);
        foreach ($document->getElementsByTagName('img') as $element) {
            $image = self::inlineImage($element);
            if ($image !== null && !$image->declaresBeacon()) {
                return $image;
            }
        }

        return null;
    }

    private static function inlineImage(Element $element): ?DeclaredImageModel
    {
        $src = trim($element->getAttribute('src') ?? '');
        if ($src === '') {
            return null;
        }

        $width = DeclaredImages::positiveDimension($element->getAttribute('width') ?? '');
        $srcsetRenditions = DeclaredRenditions::fromSrcset($element->getAttribute('srcset'));

        return new DeclaredImageModel(
            $src,
            $width,
            DeclaredImages::positiveDimension($element->getAttribute('height') ?? ''),
            // A `w` descriptor is a file's width; beside one, the width attribute is only a display size.
            $srcsetRenditions === [] ? DeclaredRenditions::ofWidth($src, $width) : $srcsetRenditions,
        );
    }

    /** @return list<DeclaredImageModel> */
    private static function mediaCandidatesIn(\DOMElement $parent): array
    {
        $candidates = [];
        foreach ($parent->childNodes as $child) {
            if (!MediaRssSlot::isContentOrThumbnail($child)) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url === '' || FeedMediaClassifier::kind($child) !== FeedMediaKind::Image) {
                continue;
            }
            $candidates[] = DeclaredImages::fromElement($child, $url);
        }

        return $candidates;
    }

    /** @return list<DeclaredImageModel> */
    private static function customImageCandidates(\DOMElement $item, string $localName): array
    {
        $candidates = [];
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '') {
                $candidates[] = DeclaredImages::fromElement($child, $url);
            }
        }

        return $candidates;
    }
}

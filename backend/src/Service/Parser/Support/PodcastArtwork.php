<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Html\Support\Srcset;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\Support\HttpsImageUrl;

/**
 * The artwork the podcast namespaces declare on a channel or an item, best first: Podcasting 2.0's <podcast:image>,
 * its deprecated <podcast:images srcset>, then <itunes:image> and <googleplay:image>.
 */
final class PodcastArtwork
{
    private const string PODCAST_NS = 'https://podcastindex.org/namespace/1.0';
    private const string GOOGLE_PLAY_NS = 'http://www.google.com/schemas/play-podcasts/1.0';

    /** A <podcast:image> may be a canvas video or a banner; only these purposes picture the show or episode. */
    private const array PICTURE_PURPOSES = ['artwork', 'social'];

    public static function of(\DOMElement $parent): ?DeclaredImageModel
    {
        return self::podcastNamespaceImage($parent)
            ?? self::hrefImage($parent, XmlHelper::ITUNES_NAMESPACE)
            ?? self::hrefImage($parent, self::GOOGLE_PLAY_NS);
    }

    private static function podcastNamespaceImage(\DOMElement $parent): ?DeclaredImageModel
    {
        return self::podcastImage($parent) ?? self::podcastImagesSrcset($parent);
    }

    private static function podcastImage(\DOMElement $parent): ?DeclaredImageModel
    {
        $candidates = [];
        foreach (XmlHelper::childElements($parent, 'image', self::PODCAST_NS) as $element) {
            $href = self::usableHref($element);
            if ($href !== null && self::picturesTheShow($element)) {
                $candidates[] = DeclaredImages::fromElement($element, $href);
            }
        }

        return DeclaredImages::widest($candidates)?->joinedWith(...$candidates);
    }

    private static function picturesTheShow(\DOMElement $element): bool
    {
        $type = strtolower(trim($element->getAttribute('type')));
        if ($type !== '' && !str_starts_with($type, 'image/')) {
            return false;
        }

        $purpose = strtolower($element->getAttribute('purpose'));
        $purposes = preg_split('/\s+/', $purpose, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return $purposes === [] || array_intersect($purposes, self::PICTURE_PURPOSES) !== [];
    }

    private static function podcastImagesSrcset(\DOMElement $parent): ?DeclaredImageModel
    {
        $srcset = XmlHelper::childElement($parent, 'images', self::PODCAST_NS)?->getAttribute('srcset');
        $renditions = DeclaredRenditions::fromSrcset($srcset);
        $widest = Srcset::widest($srcset);
        if ($renditions === [] || $widest === null) {
            return null;
        }

        return new DeclaredImageModel($widest->url, $widest->width, null, $renditions);
    }

    private static function hrefImage(\DOMElement $parent, string $namespace): ?DeclaredImageModel
    {
        foreach (XmlHelper::childElements($parent, 'image', $namespace) as $element) {
            $href = self::usableHref($element);
            if ($href !== null) {
                return new DeclaredImageModel($href);
            }
        }

        return null;
    }

    /** An href no image can be stored from (relative, ftp:) lets a lower source answer instead. */
    private static function usableHref(\DOMElement $element): ?string
    {
        $href = trim($element->getAttribute('href'));

        return HttpsImageUrl::orNullUpgrading($href) === null ? null : $href;
    }

    private function __construct()
    {
    }
}

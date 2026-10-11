<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Html\Support\Srcset;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Url\Support\HttpsImageUrl;

final class PodcastArtwork
{
    /** A <podcast:image> may be a canvas video or a banner; only these purposes picture the show or episode. */
    private const array PICTURE_PURPOSES = ['artwork', 'social'];

    public static function of(\DOMElement $parent): ?DeclaredImageModel
    {
        return self::podcastNamespaceImage($parent)
            ?? self::hrefImage($parent, FeedNamespace::Itunes)
            ?? self::hrefImage($parent, FeedNamespace::GooglePlay);
    }

    private static function podcastNamespaceImage(\DOMElement $parent): ?DeclaredImageModel
    {
        return self::podcastImage($parent) ?? self::podcastImagesSrcset($parent);
    }

    private static function podcastImage(\DOMElement $parent): ?DeclaredImageModel
    {
        $candidates = [];
        foreach (CoreElement::extension($parent, FeedNamespace::Podcast)->children('image') as $element) {
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
        $srcset = CoreElement::extension($parent, FeedNamespace::Podcast)->child('images')?->getAttribute('srcset');
        $renditions = DeclaredRenditions::fromSrcset($srcset);
        $widest = Srcset::widest($srcset);
        if ($renditions === [] || $widest === null) {
            return null;
        }

        return new DeclaredImageModel($widest->url, $widest->width, null, $renditions);
    }

    private static function hrefImage(\DOMElement $parent, FeedNamespace $namespace): ?DeclaredImageModel
    {
        foreach (CoreElement::extension($parent, $namespace)->children('image') as $element) {
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

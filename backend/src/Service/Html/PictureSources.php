<?php

declare(strict_types=1);

namespace App\Service\Html;

use App\Service\Html\Model\ImageRenditionModel;
use App\Service\Html\Support\ImageSourceUrl;
use App\Service\Html\Support\Srcset;
use Dom\Element;

/**
 * Reads a <picture>'s <source> set: the widest usable rendition, to beat a placeholder <img>, or the first usable
 * one, to give a src-less <img> a source at all.
 */
final readonly class PictureSources
{
    /** Attributes holding a candidate list, in the order publishers prefer them. */
    private const array SRCSET_ATTRIBUTES = ['data-lazy-srcset', 'data-srcset', 'srcset'];

    public function __construct(private DesktopViewport $viewport)
    {
    }

    /**
     * The widest usable rendition the <source> set offers. When no source
     * declares a width the first usable one stands in, so a src-less picture
     * still resolves to a real image.
     */
    public function widest(Element $picture): ?ImageRenditionModel
    {
        $widest = null;
        foreach ($picture->getElementsByTagName('source') as $source) {
            $rendition = $this->renditionOf($source);
            if ($rendition !== null && ($widest === null || $rendition->outsizes($widest))) {
                $widest = $rendition;
            }
        }

        return $widest;
    }

    public function firstUsableUrl(Element $picture): ?string
    {
        foreach ($picture->getElementsByTagName('source') as $source) {
            $candidate = Srcset::firstUrl($this->srcsetOf($source));
            if ($candidate !== null && ImageSourceUrl::isUsable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function renditionOf(Element $source): ?ImageRenditionModel
    {
        if (!$this->viewport->admits($source->getAttribute('media'))) {
            return null;
        }

        $candidate = Srcset::widest($this->srcsetOf($source));
        if ($candidate === null || !ImageSourceUrl::isUsable($candidate->url)) {
            return null;
        }

        return ImageRenditionModel::measuredFromUrl($candidate->url, $candidate->width);
    }

    /** A <source>'s candidate list, from the same lazy attributes an <img> is read by. */
    private function srcsetOf(Element $source): ?string
    {
        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            $srcset = $source->getAttribute($attribute);
            if ($srcset !== null && trim($srcset) !== '') {
                return $srcset;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Reader\Model\ImageIdentityModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Promotes the image a lazy-loading page hides in <noscript>, which the sanitizer would drop with the tag, and removes
 * the placeholder <img> before it. A <noscript> without an image is a no-JS text fallback and stays.
 */
final readonly class NoscriptImageUnwrapper implements PageRepairInterface
{
    public function repairIn(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('noscript')) as $noscript) {
            $this->promoteImageOutOf($noscript);
        }
    }

    private function promoteImageOutOf(Element $noscript): void
    {
        if ($noscript->getElementsByTagName('img')->length === 0) {
            return;
        }

        $this->removePrecedingPlaceholder($noscript);
        $noscript->replaceWith(...iterator_to_array($noscript->childNodes));
    }

    private function removePrecedingPlaceholder(Element $noscript): void
    {
        $sibling = $noscript->previousElementSibling;
        if ($sibling !== null && $this->isPlaceholderFor($sibling, $noscript)) {
            $sibling->remove();
        }
    }

    /**
     * A shape match alone is not enough: an unrelated content image right
     * before a noscript would otherwise be deleted as if it were the lazy-load
     * placeholder for the noscript's own photo.
     */
    private function isPlaceholderFor(Element $sibling, Element $noscript): bool
    {
        if (!$this->isSingleImageShape($sibling)) {
            return false;
        }

        $placeholderSource = $this->imageSource($sibling);
        if ($placeholderSource === '' || str_starts_with($placeholderSource, 'data:')) {
            return true;
        }

        $noscriptImageSource = $this->imageSource($noscript);

        return $noscriptImageSource !== ''
            && ImageIdentityModel::fromUrl($placeholderSource)
                ->isSameAsset(ImageIdentityModel::fromUrl($noscriptImageSource));
    }

    private function isSingleImageShape(Element $element): bool
    {
        if ($element->localName === 'img') {
            return true;
        }

        return $element->getElementsByTagName('img')->length === 1
            && trim((string) $element->textContent) === '';
    }

    private function imageSource(Element $element): string
    {
        $img = $element->localName === 'img' ? $element : $element->getElementsByTagName('img')->item(0);

        return $img?->getAttribute('src') ?? '';
    }
}

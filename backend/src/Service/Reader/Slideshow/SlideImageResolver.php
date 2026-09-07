<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * Finds the one image URL a slide points at. Real galleries carry the URL in
 * several shapes: a plain <img>, a library lazy attribute (Swiper/Owl/Flickity
 * all put it on data-src), or a lightbox <a href> to the image file — probed
 * over real pages (#926). The first hit wins; a slide that resolves to nothing
 * does not count as a slide.
 */
final readonly class SlideImageResolver
{
    private const array LAZY_ATTRIBUTES = [
        'data-src', 'data-lazy-src', 'data-original', 'data-thumb',
        'data-splide-lazy', 'data-flickity-lazyload-src',
    ];

    private const string IMAGE_FILE = '/\.(?:jpe?g|png|webp|gif|avif)(?:$|\?)/i';

    public function resolve(Element $slide): ?string
    {
        return $this->fromImg($slide)
            ?? $this->fromLazyAttribute($slide)
            ?? $this->fromAnchorHref($slide);
    }

    private function fromImg(Element $slide): ?string
    {
        foreach ($slide->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($this->isRemote($source)) {
                return $source;
            }
        }

        return null;
    }

    private function fromLazyAttribute(Element $slide): ?string
    {
        foreach ($this->selfAndDescendants($slide) as $element) {
            foreach (self::LAZY_ATTRIBUTES as $attribute) {
                $value = $element->getAttribute($attribute) ?? '';
                if ($this->isRemote($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function fromAnchorHref(Element $slide): ?string
    {
        foreach ($slide->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href') ?? '';
            if ($this->isRemote($href) && preg_match(self::IMAGE_FILE, $href) === 1) {
                return $href;
            }
        }

        return null;
    }

    /** @return iterable<Element> */
    private function selfAndDescendants(Element $slide): iterable
    {
        yield $slide;
        yield from $slide->getElementsByTagName('*');
    }

    private function isRemote(string $url): bool
    {
        return str_starts_with($url, 'http') || str_starts_with($url, '//');
    }
}

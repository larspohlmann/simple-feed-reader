<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;

/**
 * Finds the image URL each slide of a carousel points at. Real galleries carry it
 * as a plain <img>, a lazy attribute (Swiper/Owl/Flickity data-src etc), or a
 * lightbox <a href> — probed over real pages (#926). First hit wins; unresolved
 * slides don't count.
 *
 * `resolveAll` owns the placeholder case: a carousel whose slides all resolve to
 * one URL is a row of lazy placeholders, so each slide is resolved again past
 * that URL to reach the real image the lazy attribute or lightbox link holds (#1091).
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
        return $this->resolveExcluding($slide, null);
    }

    /**
     * @param list<Element> $slides
     *
     * @return list<?string> one URL (or null) per slide, in order
     */
    public function resolveAll(array $slides): array
    {
        $urls = array_map(fn (Element $slide): ?string => $this->resolveExcluding($slide, null), $slides);
        $placeholder = $this->repeatedUrl($urls);
        if ($placeholder === null) {
            return $urls;
        }

        return array_map(fn (Element $slide): ?string => $this->resolveExcluding($slide, $placeholder), $slides);
    }

    private function resolveExcluding(Element $slide, ?string $placeholder): ?string
    {
        foreach ($this->candidates($slide) as $candidate) {
            if ($candidate !== $placeholder) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The URL every resolved slide shares, or null when they don't all match it.
     *
     * @param list<?string> $urls
     */
    private function repeatedUrl(array $urls): ?string
    {
        $present = array_values(array_filter($urls, static fn (?string $url): bool => $url !== null));
        if (count($present) < 2) {
            return null;
        }

        return count(array_unique($present)) === 1 ? $present[0] : null;
    }

    /** @return iterable<string> */
    private function candidates(Element $slide): iterable
    {
        yield from $this->imgSources($slide);
        yield from $this->lazySources($slide);
        yield from $this->anchorSources($slide);
    }

    /** @return iterable<string> */
    private function imgSources(Element $slide): iterable
    {
        foreach ($slide->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($this->isRemote($source)) {
                yield $source;
            }
        }
    }

    /** @return iterable<string> */
    private function lazySources(Element $slide): iterable
    {
        foreach ($this->selfAndDescendants($slide) as $element) {
            foreach (self::LAZY_ATTRIBUTES as $attribute) {
                $value = $element->getAttribute($attribute) ?? '';
                if ($this->isRemote($value)) {
                    yield $value;
                }
            }
        }
    }

    /** @return iterable<string> */
    private function anchorSources(Element $slide): iterable
    {
        foreach ($slide->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href') ?? '';
            if ($this->isRemote($href) && preg_match(self::IMAGE_FILE, $href) === 1) {
                yield $href;
            }
        }
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

<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser;

use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Reads the raw page for inline media teasers — a player block (video or audio)
 * that carries its own still, a headline and a link — the shape readability
 * strips to a lone thumbnail. Host-agnostic: it names no publisher, only the
 * structure. A player without its own still is the article's own media or a
 * bare stream, left to the media pipeline; one in page chrome is not content.
 *
 * The URL scan mirrors AttributeMediaSource: a publisher hides its file in an
 * ad-hoc attribute (ARD's `data-v` rendition list), so every attribute is read
 * rather than a named one. Renditions of a player share its element, so it
 * stays one teaser.
 */
final readonly class TeaserPlayerScanner
{
    private const string URL_PATTERN = '#https://[^"\'\s\\\\<>]+#i';

    /** How far above the player its still and its link may sit. */
    private const int ANCESTOR_LEVELS = 4;

    public function __construct(private MediaUrlKind $kind)
    {
    }

    /** @return list<TeaserPlayer> */
    public function scan(HTMLDocument $document, string $pageUrl): array
    {
        $teasers = [];
        foreach ($this->playersByElement($document) as [$element, $kind, $mediaUrl]) {
            $poster = $this->stillAbove($element);
            if ($poster !== null) {
                [$caption, $link] = $this->captionAndLink($element);
                $teasers[$mediaUrl] ??= new TeaserPlayer(
                    $kind,
                    $mediaUrl,
                    $poster,
                    $caption,
                    $this->absolute($link, $pageUrl),
                );
            }
        }

        return array_values($teasers);
    }

    /**
     * One entry per player element, its kind and one playable URL. A file wins
     * over the stream beside it; renditions collapse onto their shared element.
     *
     * @return list<array{0: Element, 1: MediaKind, 2: string}>
     */
    private function playersByElement(HTMLDocument $document): array
    {
        $byElement = [];
        foreach ($document->querySelectorAll('*') as $element) {
            if (PageFurniture::holds($element)) {
                continue;
            }
            foreach ($this->fileUrls($element) as [$kind, $url]) {
                $player = $element->closest('video, audio') ?? $element;
                $byElement[spl_object_id($player)] ??= [$player, $kind, $url];
            }
        }

        return array_values($byElement);
    }

    /**
     * The playable file URLs an element's attributes hold, kind first — a stream
     * is skipped while a file is present, since the file plays without a library.
     *
     * @return list<array{0: MediaKind, 1: string}>
     */
    private function fileUrls(Element $element): array
    {
        $files = [];
        foreach ($element->attributes as $attribute) {
            $decoded = html_entity_decode($attribute->value, \ENT_QUOTES | \ENT_HTML5);
            if (preg_match_all(self::URL_PATTERN, $decoded, $matches) === false) {
                continue;
            }
            foreach ($matches[0] as $candidate) {
                $resolved = $this->kind->resolve($candidate);
                if ($resolved !== null && $this->isPlayableFile($resolved->kind)) {
                    $files[] = [$resolved->kind, $resolved->url];
                }
            }
        }

        return $files;
    }

    private function isPlayableFile(MediaKind $kind): bool
    {
        return $kind === MediaKind::Video || $kind === MediaKind::Audio;
    }

    private function stillAbove(Element $player): ?string
    {
        foreach ($this->ancestors($player) as $ancestor) {
            $image = $ancestor->querySelector('img[src]');
            $source = $image?->getAttribute('src') ?? '';
            if (preg_match('#^https://#i', $source) === 1) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The block's headline link, the "text and link" the teaser reduces to.
     *
     * @return array{0: ?string, 1: ?string} caption, then link URL
     */
    private function captionAndLink(Element $player): array
    {
        foreach ($this->ancestors($player) as $ancestor) {
            $link = $ancestor->querySelector('a[href]');
            $href = $link?->getAttribute('href');
            if ($link !== null && $href !== null && $href !== '') {
                return [$this->readableText($link), $href];
            }
        }

        return [null, null];
    }

    private function readableText(Element $element): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $element->textContent ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * The sanitizer drops a scheme-less link, so a root-relative href is resolved
     * against the page origin before it reaches that barrier; an absolute one stands.
     */
    private function absolute(?string $href, string $pageUrl): ?string
    {
        if ($href === null || preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $parts = parse_url($pageUrl);
        if (!isset($parts['scheme'], $parts['host']) || !str_starts_with($href, '/')) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $href;
    }

    /** @return list<Element> the player and its ancestors, nearest first */
    private function ancestors(Element $player): array
    {
        $chain = [];
        $node = $player;
        for ($level = 0; $level <= self::ANCESTOR_LEVELS; $level++) {
            if (!$node instanceof Element || $node->localName === 'body') {
                break;
            }
            $chain[] = $node;
            $node = $node->parentNode;
        }

        return $chain;
    }
}

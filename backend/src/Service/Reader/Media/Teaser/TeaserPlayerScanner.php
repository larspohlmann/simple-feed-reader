<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser;

use App\Service\Fetch\UrlResolver;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\PlayerPoster;
use App\Service\Reader\Media\ResolvedMediaUrl;
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

    /** How far above the player its headline link may sit. */
    private const int ANCESTOR_LEVELS = 4;

    public function __construct(private MediaUrlKind $kind)
    {
    }

    /** @return list<TeaserPlayer> */
    public function scan(HTMLDocument $document, string $pageUrl): array
    {
        $teasers = [];
        $seen = [];
        foreach ($document->querySelectorAll('*') as $element) {
            if (PageFurniture::holds($element)) {
                continue;
            }
            $file = $this->playableFileOn($element);
            $player = $element->closest('video, audio') ?? $element;
            if ($file === null || isset($seen[spl_object_id($player)])) {
                continue;
            }
            $seen[spl_object_id($player)] = true;
            $poster = PlayerPoster::near($player);
            if ($poster !== null) {
                [$caption, $link] = $this->captionAndLink($player);
                $teasers[$file->url] ??= new TeaserPlayer(
                    $file->kind,
                    $file->url,
                    $poster,
                    $caption,
                    $this->absolute($link, $pageUrl),
                );
            }
        }

        return array_values($teasers);
    }

    /**
     * The first playable file — a video or audio, not the bare stream beside it,
     * since the file plays without a library — that an element's attributes hold.
     */
    private function playableFileOn(Element $element): ?ResolvedMediaUrl
    {
        foreach ($element->attributes as $attribute) {
            $decoded = html_entity_decode($attribute->value, \ENT_QUOTES | \ENT_HTML5);
            if (preg_match_all(self::URL_PATTERN, $decoded, $matches) === false) {
                continue;
            }
            foreach ($matches[0] as $candidate) {
                $resolved = $this->kind->resolve($candidate);
                if ($resolved !== null && $this->isPlayableFile($resolved->kind)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function isPlayableFile(MediaKind $kind): bool
    {
        return $kind === MediaKind::Video || $kind === MediaKind::Audio;
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
     * The sanitizer drops a scheme-less link, so the headline link is resolved
     * against the page (origin, port and all) before it reaches that barrier.
     */
    private function absolute(?string $href, string $pageUrl): ?string
    {
        return $href === null ? null : UrlResolver::resolve($pageUrl, $href);
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

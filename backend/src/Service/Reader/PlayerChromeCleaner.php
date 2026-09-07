<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Repairs what readability keeps of a page's script-driven player (#786). The
 * bare <audio>/<video> has no UI at all once its script is gone, so a player
 * that still names a file gets native controls; one whose file the sanitizer
 * stripped (ZEIT hides it in data-src, #903) is dropped instead, so no dead
 * control reaches the reader. The readouts that UI left behind — blocks whose
 * whole text is clock values, `0:00` beside `-13:34` — are removed together
 * with the wrapper they leave empty. A clock inside a sentence is prose and stays.
 *
 * The same pass drops NPR's "copy this embed" widget (#922): a label and a
 * <code> block that show the player's <iframe> snippet as escaped, literal text
 * for a human to copy. It sits beside the real <audio> and is chrome, not
 * content, so the reader shows source code where an article should be.
 */
final readonly class PlayerChromeCleaner
{
    private const string CLOCK = '-?\d{1,2}:\d{2}(?::\d{2})?';

    private const string READOUT_PATTERN = '/^' . self::CLOCK . '(?:\s*[\/|]\s*' . self::CLOCK . ')*$/';

    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];

    private const string EMBED_CODE_MARK = 'npr.org/player/embed/';

    public function cleanIn(HTMLDocument $document): void
    {
        $body = $document->body;
        if ($body === null) {
            return;
        }

        $this->restoreOrDropPlayers($document);
        foreach (LeadingEngagementBlocks::in($body) as $block) {
            if (preg_match(self::READOUT_PATTERN, $block->text) === 1) {
                $this->removeWithEmptiedWrappers($block->element, $body);
            }
        }
        foreach ($this->embedCodeBlocks($document) as $code) {
            $this->removeWithEmptiedWrappers($this->embedRow($code, $body) ?? $code, $body);
        }
    }

    /** @return list<Element> collected before mutation, so removals don't skip nodes */
    private function embedCodeBlocks(HTMLDocument $document): array
    {
        $blocks = [];
        foreach ($document->querySelectorAll('code') as $code) {
            if (str_contains($code->textContent ?? '', self::EMBED_CODE_MARK)) {
                $blocks[] = $code;
            }
        }

        return $blocks;
    }

    /** The list row (else the paragraph) carrying the embed widget's label and code. */
    private function embedRow(Element $code, Element $body): ?Element
    {
        $node = $code->parentElement;
        $paragraph = null;
        while ($node !== null && $node !== $body) {
            if ($node->localName === 'li') {
                return $node;
            }
            if ($paragraph === null && $node->localName === 'p') {
                $paragraph = $node;
            }
            $node = $node->parentElement;
        }

        return $paragraph;
    }

    private function restoreOrDropPlayers(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('audio, video') as $player) {
            if ($this->hasPlayableSource($player)) {
                if (!$player->hasAttribute('controls')) {
                    $player->setAttribute('controls', '');
                }
                continue;
            }
            // The sanitizer stripped the file (ZEIT hides it in data-src, #903);
            // a bare player would show controls that can never play.
            $player->remove();
        }
    }

    private function hasPlayableSource(Element $player): bool
    {
        $source = $player->getAttribute('src');

        return ($source !== null && $source !== '')
            || $player->getElementsByTagName('source')->length > 0;
    }

    private function removeWithEmptiedWrappers(Element $readout, Element $body): void
    {
        $wrapper = $readout->parentElement;
        $readout->remove();
        while ($wrapper !== null && $wrapper !== $body && $this->isEmptied($wrapper)) {
            $next = $wrapper->parentElement;
            $wrapper->remove();
            $wrapper = $next;
        }
    }

    private function isEmptied(Element $wrapper): bool
    {
        return LeadingEngagementRules::collapse($wrapper->textContent) === ''
            && !array_any(
                self::MEDIA_TAGS,
                static fn (string $tag): bool => $wrapper->getElementsByTagName($tag)->length > 0,
            );
    }
}

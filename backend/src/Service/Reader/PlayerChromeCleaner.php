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
 */
final readonly class PlayerChromeCleaner
{
    private const string CLOCK = '-?\d{1,2}:\d{2}(?::\d{2})?';

    private const string READOUT_PATTERN = '/^' . self::CLOCK . '(?:\s*[\/|]\s*' . self::CLOCK . ')*$/';

    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];

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

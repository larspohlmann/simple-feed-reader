<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Repairs what readability keeps of a page's script-driven player (#786). The
 * bare <audio>/<video> has no UI at all once its script is gone, so it gets
 * native controls. The readouts that UI left behind — blocks whose whole text
 * is clock values, `0:00` beside `-13:34` — are removed together with the
 * wrapper they leave empty. A clock inside a sentence is prose and stays.
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

        $this->restoreNativeControls($document);
        foreach (LeadingEngagementBlocks::in($body) as $block) {
            if (preg_match(self::READOUT_PATTERN, $block->text) === 1) {
                $this->removeWithEmptiedWrappers($block->element, $body);
            }
        }
    }

    private function restoreNativeControls(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('audio, video') as $player) {
            if (!$player->hasAttribute('controls')) {
                $player->setAttribute('controls', '');
            }
        }
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

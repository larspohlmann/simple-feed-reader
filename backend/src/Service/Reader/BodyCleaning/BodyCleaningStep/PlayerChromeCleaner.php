<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Support\LeadingEngagementBlocks;
use App\Service\Text\Support\Whitespace;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Cleans up a script-driven player readability kept: a player with a file gets native controls, one without is
 * dropped, and so are clock-readout blocks, "copy this embed" snippet widgets and narration containers holding no
 * player. A clock inside a sentence is prose and stays.
 */
final readonly class PlayerChromeCleaner implements BodyCleaningStepInterface
{
    private const string CLOCK = '-?\d{1,2}:\d{2}(?::\d{2})?';

    private const string READOUT_PATTERN = '/^' . self::CLOCK . '(?:\s*[\/|]\s*' . self::CLOCK . ')*$/';

    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];

    /** A code block whose literal text is an <iframe> embed snippet (src and
     *  all) is a "copy this embed" widget, not a code sample a reader wrote. */
    private const string EMBED_SNIPPET_PATTERN = '/<iframe\b[^>]*\bsrc=/i';

    public function __construct(private NarrationSignals $narration)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->removePlayerChromeFrom($pass->document);
    }

    private function removePlayerChromeFrom(HTMLDocument $document): void
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
        foreach ($this->silentNarrationWidgets($document) as $widget) {
            $this->removeWithEmptiedWrappers($widget, $body);
        }
    }

    /**
     * Narration containers with no player of their own, outermost first so a
     * nested tell is taken with the widget rather than left behind. Collected
     * before mutation so removals don't skip nodes.
     *
     * @return list<Element>
     */
    private function silentNarrationWidgets(HTMLDocument $document): array
    {
        $widgets = [];
        foreach ($document->querySelectorAll('*') as $element) {
            if ($this->narration->declaredOn($element) && !$this->holdsPlayer($element)) {
                $widgets[] = $element;
            }
        }

        return $widgets;
    }

    private function holdsPlayer(Element $element): bool
    {
        return $element->querySelector('audio, video') !== null;
    }

    /** @return list<Element> collected before mutation, so removals don't skip nodes */
    private function embedCodeBlocks(HTMLDocument $document): array
    {
        $blocks = [];
        foreach ($document->querySelectorAll('code') as $code) {
            if (preg_match(self::EMBED_SNIPPET_PATTERN, $code->textContent ?? '') === 1) {
                $blocks[] = $code;
            }
        }

        return $blocks;
    }

    /** The list row or paragraph around the code, so removing it takes the
     *  widget's "Embed" label with the <code> block instead of leaving it. */
    private function embedRow(Element $code, Element $body): ?Element
    {
        $node = $code->parentElement;
        while ($node !== null && $node !== $body) {
            if ($node->localName === 'li' || $node->localName === 'p') {
                return $node;
            }
            $node = $node->parentElement;
        }

        return null;
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
        return Whitespace::collapse($wrapper->textContent) === ''
            && !array_any(
                self::MEDIA_TAGS,
                static fn (string $tag): bool => $wrapper->getElementsByTagName($tag)->length > 0,
            );
    }
}

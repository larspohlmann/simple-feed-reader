<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\EmptiedWrapperRemover;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Text\Support\Whitespace;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Drops a paragraph that repeats its immediate predecessor, like a dek a responsive page prints once per breakpoint.
 * Images are never de-duplicated: URL fingerprints deleted distinct photos (#1088). Recovered embed links never
 * compare, since posterless ones share the provider's fixed label.
 */
final readonly class DuplicateBlockCollapser implements BodyCleaningStepInterface
{
    public function __construct(
        private EmbedProviders $embedProviders,
        private EmptiedWrapperRemover $wrapperRemover,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->collapseIn($pass->document);
    }

    private function collapseIn(HTMLDocument $document): void
    {
        $previousText = null;
        foreach ($this->prose($document) as $paragraph) {
            $text = $this->normalize((string) $paragraph->textContent);
            if ($text === $previousText) {
                $this->wrapperRemover->removeWithEmptiedWrappers($paragraph);
                continue;
            }
            $previousText = $text;
        }
    }

    /**
     * The paragraphs compared: those with text, minus image blocks (never judged by caption) and recovered embeds.
     *
     * @return list<Element>
     */
    private function prose(HTMLDocument $document): array
    {
        $paragraphs = [];
        foreach ($document->querySelectorAll('p') as $paragraph) {
            if (trim((string) $paragraph->textContent) === '') {
                continue;
            }
            if ($paragraph->querySelector('img, picture, video, iframe, audio') !== null) {
                continue;
            }
            if ($this->isRecoveredEmbedAnchor($paragraph->querySelector('a'))) {
                continue;
            }
            $paragraphs[] = $paragraph;
        }

        return $paragraphs;
    }

    private function isRecoveredEmbedAnchor(?Element $anchor): bool
    {
        return $anchor !== null
            && $this->embedProviders->resolve((string) $anchor->getAttribute('href')) !== null;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(Whitespace::collapse($text));
    }
}

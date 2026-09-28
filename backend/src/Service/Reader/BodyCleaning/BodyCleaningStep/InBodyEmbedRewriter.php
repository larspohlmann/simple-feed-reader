<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Turns a publisher's in-body player into a link the reader renders, where the publisher put it. It runs before
 * EntrySanitizer, so the iframe still has its src; an iframe no provider claims is left for the sanitizer to drop.
 */
final readonly class InBodyEmbedRewriter implements BodyCleaningStepInterface
{
    public function __construct(
        private EmbedProviders $providers,
        private MediaMarkup $markup,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $rewritten = false;
        foreach (iterator_to_array($pass->document->getElementsByTagName('iframe')) as $iframe) {
            $rewritten = $this->rewriteOne($pass->document, $iframe) || $rewritten;
        }
        if ($rewritten) {
            $pass->recordEmbedsRecoveredInBody();
        }
    }

    private function rewriteOne(HTMLDocument $body, Element $iframe): bool
    {
        $target = $this->providers->resolve($iframe->getAttribute('src') ?? '');
        if ($target === null || $iframe->parentNode === null) {
            return false;
        }

        $iframe->parentNode->replaceChild($this->markup->embedLink($body, $target), $iframe);

        return true;
    }
}

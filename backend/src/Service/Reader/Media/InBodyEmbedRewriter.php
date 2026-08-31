<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Turns a publisher's in-body player into a link the reader can render.
 *
 * This runs before EntrySanitizer, so the iframe is still present with its src
 * and at the position the publisher chose — which is why the media needs no
 * re-fetch and lands back exactly where it belongs. Rewriting rather than
 * removing also disposes of the empty containers the sanitizer used to leave.
 *
 * An iframe no provider claims is left untouched, and the sanitizer drops it as
 * it does today.
 */
final readonly class InBodyEmbedRewriter
{
    public function __construct(
        private EmbedProviders $providers,
        private MediaMarkup $markup,
    ) {
    }

    public function rewriteIn(HTMLDocument $body): bool
    {
        $rewritten = false;
        foreach (iterator_to_array($body->getElementsByTagName('iframe')) as $iframe) {
            $rewritten = $this->rewriteOne($body, $iframe) || $rewritten;
        }

        return $rewritten;
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

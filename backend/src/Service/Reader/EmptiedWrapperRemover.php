<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Text\Support\Whitespace;
use Dom\Element;

/** Removes what a removal leaves empty, wrapper by wrapper, up to but never including <body>. */
final readonly class EmptiedWrapperRemover
{
    /** Content without text; a <br>, an <hr> or a form control alone leaves a wrapper empty. */
    private const string CONTENT_WITHOUT_TEXT = 'img, picture, svg, video, audio, iframe';

    public function removeWithEmptiedWrappers(Element $node): void
    {
        $wrapper = $node->parentElement;
        $node->remove();
        if ($wrapper !== null) {
            $this->removeIfEmptied($wrapper);
        }
    }

    public function removeIfEmptied(Element $element): void
    {
        if ($this->isEmptied($element)) {
            $this->removeWithEmptiedWrappers($element);
        }
    }

    private function isEmptied(Element $element): bool
    {
        return $this->isInsideBody($element)
            && Whitespace::collapse($element->textContent) === ''
            && !$this->isOrHoldsContent($element);
    }

    private function isInsideBody(Element $element): bool
    {
        return $element->parentElement?->closest('body') !== null;
    }

    private function isOrHoldsContent(Element $element): bool
    {
        return $element->matches(self::CONTENT_WITHOUT_TEXT)
            || $element->querySelector(self::CONTENT_WITHOUT_TEXT) !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\AudioClipModel;
use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * What a page says of one clip in the data attributes beside its URL. A bare `title` names the play button's action,
 * not the clip, so only `data-…title` counts.
 */
final readonly class AudioClipFacts
{
    private const string TITLE_ATTRIBUTE = '/^data-[\w-]*title$/';
    private const string DURATION_ATTRIBUTE = '/^data-[\w-]*duration$/';

    public function __construct(private PageFurniture $furniture)
    {
    }

    public function of(HTMLDocument $document, string $clipUrl): AudioClipModel
    {
        $title = null;
        $durationSeconds = null;
        foreach ($this->holders($document, $clipUrl) as $holder) {
            $title ??= $this->titleOn($holder);
            $durationSeconds ??= $this->durationOn($holder);
        }

        return new AudioClipModel($clipUrl, $title, $durationSeconds);
    }

    /** @return iterable<Element> */
    private function holders(HTMLDocument $document, string $clipUrl): iterable
    {
        foreach ($document->querySelectorAll('*') as $element) {
            if (!$this->furniture->holds($element) && $this->holdsUrl($element, $clipUrl)) {
                yield $element;
            }
        }
    }

    private function holdsUrl(Element $element, string $clipUrl): bool
    {
        foreach ($element->attributes as $attribute) {
            if (str_contains($attribute->value, $clipUrl)) {
                return true;
            }
        }

        return false;
    }

    private function titleOn(Element $element): ?string
    {
        $title = trim($this->firstNamed($element, self::TITLE_ATTRIBUTE)->value ?? '');

        return $title === '' ? null : $title;
    }

    private function durationOn(Element $element): ?int
    {
        foreach ($element->attributes as $attribute) {
            if (preg_match(self::DURATION_ATTRIBUTE, $attribute->name) === 1) {
                $seconds = AudioClipModel::secondsFrom($attribute->value);
                if ($seconds !== null) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    private function firstNamed(Element $element, string $pattern): ?Attr
    {
        foreach ($element->attributes as $attribute) {
            if (preg_match($pattern, $attribute->name) === 1) {
                return $attribute;
            }
        }

        return null;
    }
}

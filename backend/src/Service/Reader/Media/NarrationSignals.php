<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Recognises a publisher's text-to-speech reading of the article by a tell in the file URL or on the player or its
 * ancestors. The reader keeps such a player but marks it, so the client renders it small.
 */
final readonly class NarrationSignals
{
    /** Each token with the publisher it was measured on (#903, #959 survey). */
    private const array NARRATION_TOKENS = [
        'text-to-speech', // Süddeutsche: file path /text-to-speech/full/
        'texttospeech',   // CBC: icon texttospeech.svg, wrapper class textToSpeech
        'speechbert',     // ZEIT: narration file host zon-speechbert-production
        'readspeaker',    // heise, Belltower: ReadSpeaker widget
        'read-aloud',     // heise: data-read-aloud-url
        'tts',            // ZEIT: wrapper data-audio-type="tts"
    ];

    public function narrates(string $fileUrl, ?Element $holder): bool
    {
        return self::declaresNarration($fileUrl) || $this->holderChainDeclaresNarration($holder);
    }

    /** Whether the element's own attributes name narration, as a silent text-to-speech widget's container does. */
    public function declaredOn(Element $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (self::declaresNarration($attribute->value)) {
                return true;
            }
        }

        return false;
    }

    private function holderChainDeclaresNarration(?Element $holder): bool
    {
        for ($element = $holder; $element !== null; $element = $element->parentElement) {
            if ($this->declaredOn($element)) {
                return true;
            }
        }

        return false;
    }

    private static function declaresNarration(string $value): bool
    {
        return preg_match(self::tokenPattern(), $value) === 1;
    }

    /** A token counts only as a whole word, so `/tts/` reads but `https://` does not. */
    private static function tokenPattern(): string
    {
        return '/(?<![a-z0-9])(?:' . implode('|', self::NARRATION_TOKENS) . ')(?![a-z0-9])/i';
    }
}

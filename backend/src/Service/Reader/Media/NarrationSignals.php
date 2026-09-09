<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Recognises machine-generated narration — a publisher's text-to-speech reading
 * of the article, offered beside the prose. The file URL or any attribute on the
 * player or its ancestors carries the tell; the reader keeps such a player but
 * marks it so the client can render it small and unobtrusive (#903).
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

    public static function narrates(string $fileUrl, ?Element $holder): bool
    {
        return self::declaresNarration($fileUrl) || self::holderChainDeclaresNarration($holder);
    }

    /** Whether the element's own attributes name narration — the tell a silent
     *  text-to-speech widget carries on its container or icon (#959). */
    public static function declaredOn(Element $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if (self::declaresNarration($attribute->value)) {
                return true;
            }
        }

        return false;
    }

    private static function holderChainDeclaresNarration(?Element $holder): bool
    {
        for ($element = $holder; $element !== null; $element = $element->parentElement) {
            if (self::declaredOn($element)) {
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

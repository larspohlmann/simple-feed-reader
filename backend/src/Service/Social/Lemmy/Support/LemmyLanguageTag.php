<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

/** The tag of a Lemmy post's `language_id`; the ids are the same on every instance. */
final class LemmyLanguageTag
{
    private const int UNDETERMINED = 0;

    private const array TAGS = [32 => 'de', 37 => 'en', 39 => 'es', 47 => 'fr'];

    /** @return string|null null when the post states no language; a private-use tag for one outside the four */
    public static function fromLanguageId(?int $languageId): ?string
    {
        if (null === $languageId || self::UNDETERMINED === $languageId) {
            return null;
        }

        return self::TAGS[$languageId] ?? 'x-lemmy-' . $languageId;
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Service\Text\EntryPlainText;

/**
 * Produces PLAIN TEXT, not HTML, cut to MAX_LENGTH, from an entry body —
 * see EntryPlainText for the image-strip and junk-token rules this builds on.
 *
 * The result may contain <, > and & as literal characters and has NOT been
 * through EntrySanitizer. Render as text only, never with |raw or innerHTML.
 */
final class EntrySnippet
{
    private const int MAX_LENGTH = 500;

    public static function from(?string $html): ?string
    {
        $text = EntryPlainText::of($html);

        return $text === null ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }
}

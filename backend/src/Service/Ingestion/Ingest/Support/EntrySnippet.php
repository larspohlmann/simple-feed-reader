<?php

declare(strict_types=1);

namespace App\Service\Ingest\Support;

use App\Service\Text\Support\EntryPlainText;

/**
 * An entry body as PLAIN TEXT (EntryPlainText's rules) cut to MAX_LENGTH. Never sanitized: it may hold literal <, >
 * and &, so render it as text only, never with |raw or innerHTML.
 */
final class EntrySnippet
{
    private const int MAX_LENGTH = 500;

    public static function from(?string $html): ?string
    {
        $text = EntryPlainText::of($html);

        return $text === null ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }

    private function __construct()
    {
    }
}

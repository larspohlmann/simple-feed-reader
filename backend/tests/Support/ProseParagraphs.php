<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Paragraphs several reader tests share; each clears a length bar the code under test measures. */
final class ProseParagraphs
{
    /** Clears the body cleaners' substantial-prose length and holds no link. */
    public const string SUBSTANTIAL = 'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    /** The block a player follows on its page, long enough for PageTextBlocksModel to anchor to. */
    public const string BEFORE_A_PLAYER =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private function __construct()
    {
    }
}

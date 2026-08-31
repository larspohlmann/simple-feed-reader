<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Where a phrase family is allowed to match. Both cases exist because the same
 * words mean different things in different places, and matching everywhere makes
 * a family useless (#744).
 */
enum PhraseScope
{
    /**
     * Above the article's first paragraph. Below it, the wording belongs to the
     * site's own tail, which the reader's user reaches after reading.
     */
    case AboveTheArticle;

    /**
     * Only on a body that never reaches a paragraph. A wall means the reader
     * showed no article at all — so on a body that HAS an article, the same
     * words are the article's own: a newsletter box's fine print promising a
     * Datenschutzerklärung, or a piece writing about cookie banners.
     */
    case OnlyWhenNoArticle;
}

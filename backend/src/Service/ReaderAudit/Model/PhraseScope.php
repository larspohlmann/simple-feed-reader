<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/** Where a phrase family may match: the same words mean different things in different places. */
enum PhraseScope
{
    /**
     * Above the article's first paragraph. Below it, the wording belongs to the
     * site's own tail, which the reader's user reaches after reading.
     */
    case AboveTheArticle;

    /**
     * Only on a body that never reaches a paragraph: a wall is the absence of the article, so on a body that has one
     * the same words are its own, such as a newsletter box's privacy fine print.
     */
    case OnlyWhenNoArticle;
}

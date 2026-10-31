<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Html\Support\ClassTokenMatcher;
use Dom\HTMLDocument;

/**
 * Removes a page's reader-comment thread, found by a whole class token, while readability can still see the class: a
 * short page is re-grabbed without its unlikely-candidate filter, and that grab keeps the thread as article text.
 */
final readonly class CommentThreadRemover implements PageRepairInterface
{
    private const array COMMENT_THREAD_CLASS_TOKENS = [
        'comments', 'comments-area', 'commentlist', 'comment-list', 'comment-respond', 'comment-form',
    ];

    public function repairIn(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('[class]') as $element) {
            if (ClassTokenMatcher::hasAnyToken($element, self::COMMENT_THREAD_CLASS_TOKENS)) {
                $element->remove();
            }
        }
    }
}

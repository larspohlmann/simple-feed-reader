<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use App\Service\Url\AbsoluteHttpUrl;

/** Reads an Atom entry's rel="replies" links into a Discussion. */
final class AtomDiscussion
{
    public static function from(\DOMElement $entry, string $ns): Discussion
    {
        $page = null;
        $commentsFeed = null;
        foreach (self::repliesLinks($entry, $ns) as $link) {
            $href = trim($link->getAttribute('href'));
            if (self::isFeedType($link->getAttribute('type'))) {
                $commentsFeed ??= $href;
                continue;
            }
            $page ??= $href;
        }

        if ($commentsFeed !== null) {
            return Discussion::withCommentsFeed($page, $commentsFeed, CommentsLoad::Manual);
        }

        return $page === null ? Discussion::none() : Discussion::page($page);
    }

    private static function isFeedType(string $type): bool
    {
        return preg_match('#(atom|rss)\+xml$|/xml$#i', $type) === 1;
    }

    /** @return iterable<\DOMElement> */
    private static function repliesLinks(\DOMElement $parent, string $ns): iterable
    {
        foreach ($parent->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'link'
                && $child->namespaceURI === $ns
                && $child->getAttribute('rel') === 'replies'
                && AbsoluteHttpUrl::matches(trim($child->getAttribute('href')))
            ) {
                yield $child;
            }
        }
    }
}

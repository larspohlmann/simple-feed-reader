<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use App\Service\Url\AbsoluteHttpUrl;

final class AtomDiscussion
{
    public static function from(\DOMElement $entry, string $ns): Discussion
    {
        $page = null;
        $commentsFeed = null;
        foreach (self::repliesLinks($entry, $ns) as $link) {
            $href = trim($link->getAttribute('href'));
            if (!AbsoluteHttpUrl::matches($href)) {
                continue;
            }
            if (self::isFeedType($link->getAttribute('type'))) {
                $commentsFeed ??= $href;
                continue;
            }
            $page ??= $href;
        }

        return Discussion::of($page, $commentsFeed, CommentsLoad::Manual);
    }

    private static function isFeedType(string $type): bool
    {
        return preg_match('#(atom|rss)\+xml$|/xml$#i', $type) === 1;
    }

    /** @return iterable<\DOMElement> */
    private static function repliesLinks(\DOMElement $entry, string $ns): iterable
    {
        foreach (XmlHelper::childElements($entry, 'link', $ns) as $link) {
            if ($link->getAttribute('rel') === 'replies') {
                yield $link;
            }
        }
    }
}

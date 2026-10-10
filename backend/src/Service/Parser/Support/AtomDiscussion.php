<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Url\Support\AbsoluteHttpUrl;

final class AtomDiscussion
{
    public static function from(CoreElement $entry): Discussion
    {
        $page = null;
        $commentsFeed = null;
        foreach (self::repliesLinks($entry) as $link) {
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
    private static function repliesLinks(CoreElement $entry): iterable
    {
        foreach ($entry->children('link') as $link) {
            if ($link->getAttribute('rel') === 'replies') {
                yield $link;
            }
        }
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Comments\Support;

use App\Entity\Entry;
use App\Enum\CommentsLoad;
use App\Service\Comments\Model\LemmyPostModel;

final class LemmyPostAddress
{
    private const string POST_PAGE = '#^https://([a-z0-9.-]+)/post/(\d+)/?$#i';

    public static function of(Entry $entry): ?LemmyPostModel
    {
        $discussion = $entry->getDiscussion();
        if (!$entry->isDiscussionThread() || null !== $discussion->commentsFeedUrl) {
            return null;
        }
        if (CommentsLoad::Auto !== $discussion->commentsLoad) {
            return null;
        }
        if (1 !== preg_match(self::POST_PAGE, (string) $discussion->url, $parts)) {
            return null;
        }

        return new LemmyPostModel('https://' . strtolower($parts[1]), (int) $parts[2]);
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Comments\Support;

use App\Entity\Entry;
use App\Enum\CommentsLoad;
use App\Service\Comments\Model\LemmyPostModel;
use App\Service\Url\Support\LemmyPostUrl;

final class LemmyPostAddress
{
    public static function of(Entry $entry): ?LemmyPostModel
    {
        $discussion = $entry->getDiscussion();
        if (
            !$entry->isDiscussionThread()
            || null !== $discussion->commentsFeedUrl
            || CommentsLoad::Auto !== $discussion->commentsLoad
        ) {
            return null;
        }
        $page = LemmyPostUrl::parse($discussion->url);

        return $page === null ? null : new LemmyPostModel('https://' . $page->host, $page->postId);
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Factory;

use App\Entity\Entry;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Service\Comments\Model\EntryCommentModel;

final readonly class ThreadPostFactory
{
    public function build(Entry $entry, int $position, EntryCommentModel $reply): ThreadPost
    {
        return new ThreadPost($entry, $position, new ThreadPostContent(
            $reply->url,
            $reply->publishedAt?->setTimezone(new \DateTimeZone('UTC')),
            $reply->html,
            $reply->embeds->media,
        ));
    }
}

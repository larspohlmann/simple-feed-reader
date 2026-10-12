<?php

declare(strict_types=1);

namespace App\Service\Ingest\FeedTitle;

interface FeedTitleInterface
{
    /** The title a feed is stored under, given the title its channel names itself by. */
    public function of(string $feedUrl, string $channelTitle): string;
}

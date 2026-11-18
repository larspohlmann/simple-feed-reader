<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Model;

use App\Entity\DiscussionCounts;
use App\Entity\PostCommunity;

final readonly class LemmyFeedPostModel
{
    public function __construct(
        public string $authorName,
        public string $profileUrl,
        public string $bodyHtml,
        public DiscussionCounts $counts,
        public ?PostCommunity $community,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreFeeds;

use App\Entity\Feed;

interface RestoreFeedsInterface
{
    /**
     * @param list<string> $urls
     *
     * @return array<string, Feed>
     */
    public function findByUrlsIndexedByUrl(array $urls): array;

    public function isReadByAnotherUser(int $feedId, int $excludedUserId): bool;
}

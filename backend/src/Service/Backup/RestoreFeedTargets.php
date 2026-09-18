<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Repository\EntryRepository;
use App\Repository\FeedRepository;

/**
 * One RestoreFeedTarget per feed url the entry part names, built on first use
 * from the feed ids EntryPartInspector resolved. Per pass, never shared.
 */
final class RestoreFeedTargets
{
    /** @var array<string, RestoreFeedTarget> */
    private array $targets = [];

    public function __construct(
        private readonly int $userId,
        /** @var array<string, int> */
        private readonly array $feedIdsByUrl,
        private readonly FeedRepository $feeds,
        private readonly EntryRepository $entries,
    ) {
    }

    public function for(string $feedUrl): RestoreFeedTarget
    {
        return $this->targets[$feedUrl] ??= $this->build($feedUrl);
    }

    private function build(string $feedUrl): RestoreFeedTarget
    {
        $feedId = $this->feedIdsByUrl[$feedUrl]
            ?? throw new \LogicException(sprintf('The inspection pass never resolved feed "%s".', $feedUrl));

        return new RestoreFeedTarget(
            $feedId,
            !$this->feeds->isReadByAnotherUser($feedId, $this->userId),
            $this->entries->guidHashToIdMapForFeed($feedId),
        );
    }
}

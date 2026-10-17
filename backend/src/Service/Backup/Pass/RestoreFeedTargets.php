<?php

declare(strict_types=1);

namespace App\Service\Backup\Pass;

use App\Service\Backup\RestoreEntries\RestoreEntriesInterface;
use App\Service\Backup\RestoreFeeds\RestoreFeedsInterface;

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
        private readonly RestoreFeedsInterface $feeds,
        private readonly RestoreEntriesInterface $entries,
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

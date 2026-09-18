<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\Exception\BackupLoadFailedException;

/**
 * Builds one RestoreFeedTarget per feed url the entry part actually mentions,
 * the first time it is asked for — never the whole subscription list up
 * front, since most restores touch a handful of feeds per part out of
 * however many the account holds.
 *
 * Per pass, never shared: memoises for the lifetime of one load.
 */
final class RestoreFeedTargets
{
    /** @var array<string, RestoreFeedTarget> */
    private array $targets = [];

    public function __construct(
        private readonly int $userId,
        private readonly SubscriptionRepository $subscriptions,
        private readonly FeedRepository $feeds,
        private readonly EntryRepository $entries,
    ) {
    }

    public function for(string $feedUrl): RestoreFeedTarget
    {
        return $this->targets[$feedUrl] ??= $this->build($feedUrl);
    }

    /**
     * A backstop: EntryPartInspector refuses an unsubscribed feed url in pass
     * 1, while the account is still whole. Reaching this means the two passes
     * disagree about the same bytes.
     */
    private function build(string $feedUrl): RestoreFeedTarget
    {
        $feedIds = $this->subscriptions->feedIdsByUrlForUser($this->userId, [$feedUrl]);
        $feedId = $feedIds[$feedUrl] ?? throw BackupLoadFailedException::danglingReference(sprintf(
            'The backup carries rows for feed "%s", which none of its subscriptions names.',
            $feedUrl,
        ));

        return new RestoreFeedTarget(
            $feedId,
            !$this->feeds->isReadByAnotherUser($feedId, $this->userId),
            $this->entries->guidHashToIdMapForFeed($feedId),
        );
    }
}

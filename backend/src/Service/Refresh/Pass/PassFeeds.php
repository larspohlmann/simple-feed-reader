<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\PrivateFeedOrphanedException;

/** One pass's feeds as its budget starts them, and the social feed behind each private one. */
final readonly class PassFeeds
{
    /** @param array<int, SocialFeed> $socialFeedsByFeedId each with its account loaded */
    public function __construct(private RefreshPass $pass, private array $socialFeedsByFeedId)
    {
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function started(): \Generator
    {
        return $this->pass->startedFeeds();
    }

    public function isPrivate(int|string $feedId): bool
    {
        return SourceFormat::isSocial($this->pass->feed($feedId)->getSourceFormat());
    }

    /** @throws PrivateFeedOrphanedException when the private feed has lost its social feed */
    public function socialFeedOf(int $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId] ?? throw new PrivateFeedOrphanedException();
    }
}

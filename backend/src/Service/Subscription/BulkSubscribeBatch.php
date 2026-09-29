<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\Model\BulkSubscribeResultModel;

final class BulkSubscribeBatch
{
    private BulkSubscribeResultModel $result;

    /** @var array<string, Subscription> keyed by the item's feed URL: a URL listed twice subscribes once */
    private array $subscriptionsByUrl = [];

    /** @var array<string, Tag> keyed by lowercased name: items naming one tag share one unflushed row */
    private array $tagsByName = [];

    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public readonly User $user,
        private int $room,
        public readonly BulkSubscribePositions $positions,
    ) {
        $this->result = new BulkSubscribeResultModel();
    }

    public function result(): BulkSubscribeResultModel
    {
        return $this->result;
    }

    public function hasSubscribed(string $url): bool
    {
        return isset($this->subscriptionsByUrl[$url]);
    }

    public function isFull(): bool
    {
        return $this->room <= 0;
    }

    public function tagNamed(string $name): ?Tag
    {
        return $this->tagsByName[mb_strtolower($name)] ?? null;
    }

    public function rememberTag(string $name, Tag $tag): void
    {
        $this->tagsByName[mb_strtolower($name)] = $tag;
    }

    public function countInvalid(): void
    {
        $this->result = $this->result->with(invalid: 1);
    }

    public function countAlreadySubscribed(): void
    {
        $this->result = $this->result->with(alreadySubscribed: 1);
    }

    public function countOverLimit(): void
    {
        $this->result = $this->result->with(skippedOverLimit: 1);
    }

    /**
     * @param list<Tag> $tagsCreated
     */
    public function recordSubscribed(string $url, Subscription $subscription, array $tagsCreated): void
    {
        $this->subscriptionsByUrl[$url] = $subscription;
        --$this->room;
        $this->result = $this->result->with(imported: 1, tagsCreated: $tagsCreated);
    }
}

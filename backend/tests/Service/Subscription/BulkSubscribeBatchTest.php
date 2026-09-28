<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscribeBatch;
use App\Service\Subscription\BulkSubscribePositions;
use PHPUnit\Framework\TestCase;

final class BulkSubscribeBatchTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('batch@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
    }

    public function testEachSkipIsCountedInItsOwnField(): void
    {
        $batch = $this->batch(5);

        $batch->countInvalid();
        $batch->countAlreadySubscribed();
        $batch->countAlreadySubscribed();
        $batch->countOverLimit();

        self::assertSame(1, $batch->result()->invalid);
        self::assertSame(2, $batch->result()->alreadySubscribed);
        self::assertSame(1, $batch->result()->skippedOverLimit);
        self::assertSame(0, $batch->result()->imported);
    }

    public function testASubscriptionIsRememberedByUrlAndTakesRoom(): void
    {
        $batch = $this->batch(1);
        $tag = new Tag($this->user, 'Tag');
        $url = 'https://a.example.com/rss.xml';

        self::assertFalse($batch->isFull());
        $batch->recordSubscribed($url, $this->subscription($url), [$tag]);

        self::assertTrue($batch->hasSubscribed($url));
        self::assertFalse($batch->hasSubscribed('https://b.example.com/rss.xml'));
        self::assertTrue($batch->isFull());
        self::assertSame(1, $batch->result()->imported);
        self::assertSame([$tag], $batch->result()->tagsCreated);
    }

    public function testNoRoomLeftIsFull(): void
    {
        self::assertTrue($this->batch(0)->isFull());
        self::assertTrue($this->batch(-1)->isFull());
    }

    public function testATagIsFoundByItsNameInAnyCase(): void
    {
        $batch = $this->batch(5);
        $tag = new Tag($this->user, 'Technology');

        $batch->rememberTag('Technology', $tag);

        self::assertSame($tag, $batch->tagNamed('TECHNOLOGY'));
        self::assertNull($batch->tagNamed('Science'));
    }

    public function testATagNameIsMatchedInAnyCaseBeyondAscii(): void
    {
        $batch = $this->batch(5);
        $tag = new Tag($this->user, 'Ärger');

        $batch->rememberTag('Ärger', $tag);

        self::assertSame($tag, $batch->tagNamed('ÄRGER'));
    }

    private function batch(int $room): BulkSubscribeBatch
    {
        return new BulkSubscribeBatch($this->user, $room, new BulkSubscribePositions(0, 0));
    }

    private function subscription(string $url): Subscription
    {
        return new Subscription($this->user, new Feed($url), new \DateTimeImmutable('2026-07-01 00:00:00'));
    }
}

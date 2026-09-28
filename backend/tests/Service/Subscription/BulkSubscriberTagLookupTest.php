<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscriber;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;

final class BulkSubscriberTagLookupTest extends DbTestCase
{
    use SeedsUsers;

    public function testATagTheBatchAlreadyKnowsIsNotLookedUpAgain(): void
    {
        $user = $this->user('lookup@example.com');
        $subscriber = self::getContainer()->get(BulkSubscriber::class);
        self::assertInstanceOf(BulkSubscriber::class, $subscriber);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $subscriber->subscribeAll($user, [
            new BulkSubscribeItem('https://a.example.com/rss.xml', 'A Feed', 'Technology', null),
            new BulkSubscribeItem('https://b.example.com/rss.xml', 'B Feed', 'TECHNOLOGY', null),
            new BulkSubscribeItem('https://c.example.com/rss.xml', 'C Feed', 'technology', null),
        ]);

        self::assertCount(1, $recorder->queriesMatching('lower('));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
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

        $result = $subscriber->subscribeAll($user, [
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', 'Technology', null),
            new BulkSubscribeItemModel('https://b.example.com/rss.xml', 'B Feed', 'TECHNOLOGY', null),
            new BulkSubscribeItemModel('https://c.example.com/rss.xml', 'C Feed', 'technology', null),
        ]);

        self::assertSame(3, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        $tag = $result->tagsCreated[0];
        foreach (['a', 'b', 'c'] as $letter) {
            $subscription = $this->em->getRepository(Subscription::class)->findOneBy([
                'user' => $user,
                'feed' => $this->em->getRepository(Feed::class)->findOneBy([
                    'url' => sprintf('https://%s.example.com/rss.xml', $letter),
                ]),
            ]);
            self::assertInstanceOf(Subscription::class, $subscription);
            self::assertTrue(
                $subscription->getTags()->contains($tag),
                sprintf('subscription "%s" carries the tag', $letter),
            );
        }

        self::assertCount(
            1,
            $recorder->queriesMatching('lower('),
            'one tag lookup for three items naming one tag: the batch answers the other two',
        );
    }
}

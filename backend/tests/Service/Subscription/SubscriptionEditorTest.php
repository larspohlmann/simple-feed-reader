<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionChange;
use App\Service\Subscription\SubscriptionEditor;
use App\Service\Subscription\TagMove;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;

final class SubscriptionEditorTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testUpdateStoresAnEmptyCustomTitleAsNone(): void
    {
        $user = $this->user('title-clear@example.com');
        $subscription = $this->subscription($user, 'https://clear.editor.example.com/rss');
        $subscription->setCustomTitle('Old');
        $this->em->flush();

        $this->editor()->update($subscription, new SubscriptionChange(''));

        self::assertNull($this->reload($subscription)->getCustomTitle());
    }

    public function testUpdateKeepsANonEmptyCustomTitle(): void
    {
        $user = $this->user('title-keep@example.com');
        $subscription = $this->subscription($user, 'https://keep.editor.example.com/rss');

        $this->editor()->update($subscription, new SubscriptionChange('Mine'));

        self::assertSame('Mine', $this->reload($subscription)->getCustomTitle());
    }

    public function testUpdateLeavesFlagsTheChangeOmits(): void
    {
        $user = $this->user('flags-keep@example.com');
        $subscription = $this->subscription($user, 'https://flags-keep.editor.example.com/rss');
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);
        $this->em->flush();

        $this->editor()->update($subscription, new SubscriptionChange(null));

        $reloaded = $this->reload($subscription);
        self::assertFalse($reloaded->isIncludeInAllItems());
        self::assertFalse($reloaded->isIncludeInForYou());
    }

    public function testUpdateAppliesFlagsTheChangeCarries(): void
    {
        $user = $this->user('flags-set@example.com');
        $subscription = $this->subscription($user, 'https://flags-set.editor.example.com/rss');
        $subscription->setIncludeInAllItems(true);
        $subscription->setIncludeInForYou(true);
        $this->em->flush();

        $this->editor()->update($subscription, new SubscriptionChange(null, [], false, false));

        $reloaded = $this->reload($subscription);
        self::assertFalse($reloaded->isIncludeInAllItems());
        self::assertFalse($reloaded->isIncludeInForYou());
    }

    public function testUpdateSyncsTheRequestedTags(): void
    {
        $user = $this->user('tags-sync@example.com');
        $tag = $this->tag($user, 'Synced');
        $subscription = $this->subscription($user, 'https://sync.editor.example.com/rss');

        $this->editor()->update($subscription, new SubscriptionChange(null, [$tag->requireId()]));

        self::assertSame(['Synced'], $this->tagNames($this->reload($subscription)));
    }

    public function testMoveToTagPersistsTheMove(): void
    {
        $user = $this->user('move@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $subscription = $this->subscription($user, 'https://move.editor.example.com/rss');
        $subscription->addTag($news);
        $this->em->flush();

        $this->editor()->moveToTag($subscription, new TagMove($news->requireId(), $tech->requireId()));

        self::assertSame(['Tech'], $this->tagNames($this->reload($subscription)));
    }

    public function testReorderGivesEachFeedItsIndex(): void
    {
        $user = $this->user('reorder@example.com');
        $first = $this->subscription($user, 'https://first.editor.example.com/rss');
        $second = $this->subscription($user, 'https://second.editor.example.com/rss');
        $first->setPosition(5);
        $second->setPosition(7);
        $this->em->flush();

        $this->editor()->reorder($user, [$second->requireId(), $first->requireId()]);

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    private function editor(): SubscriptionEditor
    {
        $editor = self::getContainer()->get(SubscriptionEditor::class);
        self::assertInstanceOf(SubscriptionEditor::class, $editor);

        return $editor;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /** @return list<string> */
    private function tagNames(Subscription $subscription): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        ));
    }
}

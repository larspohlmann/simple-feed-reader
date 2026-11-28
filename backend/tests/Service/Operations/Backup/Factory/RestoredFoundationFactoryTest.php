<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Factory;

use App\Entity\Feed;
use App\Entity\InclusionSwitches;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Enum\InclusionSurface;
use App\Service\Ingestion\Feed\Factory\FeedFactory;
use App\Service\Library\Tag\Factory\TagFactory;
use App\Service\Operations\Backup\Dto\FeedLine;
use App\Service\Operations\Backup\Dto\SubscriptionLine;
use App\Service\Operations\Backup\Dto\TagLine;
use App\Service\Operations\Backup\Factory\RestoredFoundationFactory;
use PHPUnit\Framework\TestCase;

final class RestoredFoundationFactoryTest extends TestCase
{
    public function testATagIsRestoredAsTheFileStatesIt(): void
    {
        $tag = $this->factory()->tag($this->user(), new TagLine('Science', '#123456', 'flask', 4), null);

        self::assertSame('Science', $tag->getName());
        self::assertSame('#123456', $tag->getColor());
        self::assertSame('flask', $tag->getIcon());
        self::assertSame(4, $tag->getPosition());
    }

    public function testATagIsRestoredWithItsSwitches(): void
    {
        $tag = $this->factory()->tag(
            $this->user(),
            new TagLine(
                name: 'Noise',
                color: null,
                icon: null,
                position: 0,
                inclusions: new InclusionSwitches(false, true, false, false, true),
            ),
            null,
        );

        self::assertFalse($tag->isIncludedIn(InclusionSurface::AllItems));
        self::assertTrue($tag->isIncludedIn(InclusionSurface::ForYou));
        self::assertFalse($tag->isIncludedIn(InclusionSurface::SavedSearches));
        self::assertFalse($tag->isIncludedIn(InclusionSurface::Trending));
        self::assertTrue($tag->isIncludedIn(InclusionSurface::YourStream));
    }

    public function testATagIsRestoredUnderTheParentItNames(): void
    {
        $user = $this->user();
        $parent = $this->factory()->tag($user, new TagLine('Reading', null, null, 1), null);

        $child = $this->factory()->tag($user, new TagLine('Long reads', null, null, 2, 'Reading'), $parent);

        self::assertSame($parent, $child->getParent());
    }

    public function testAFeedIsRestoredWithEveryFieldTheFileCarries(): void
    {
        $feed = $this->factory()->feed(new FeedLine(
            url: 'https://restored.example/feed.xml',
            siteUrl: 'https://restored.example',
            title: 'Restored',
            description: 'A restored feed',
            faviconUrl: 'https://restored.example/favicon.ico',
            imageUrl: 'https://restored.example/logo.png',
            sourceFormat: 'scraped',
            language: FeedLanguage::Spanish,
        ));

        self::assertSame('https://restored.example/feed.xml', $feed->getUrl());
        self::assertSame('https://restored.example', $feed->getSiteUrl());
        self::assertSame('Restored', $feed->getTitle());
        self::assertSame('A restored feed', $feed->getDescription());
        self::assertSame('https://restored.example/favicon.ico', $feed->getFaviconUrl());
        self::assertSame('https://restored.example/logo.png', $feed->getImageUrl());
        self::assertSame('scraped', $feed->getSourceFormat());
        self::assertSame(FeedLanguage::Spanish, $feed->getLanguage());
    }

    public function testASubscriptionIsRestoredWithItsSettings(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-01 08:00:00');
        $readUntil = new \DateTimeImmutable('2026-07-15 09:30:00');

        $subscription = $this->factory()->subscription(
            $this->user(),
            new Feed('https://restored.example/feed.xml'),
            new SubscriptionLine(
                feedUrl: 'https://restored.example/feed.xml',
                customTitle: 'My name for it',
                position: 3,
                markedReadUntil: $readUntil,
                createdAt: $createdAt,
                tags: [],
                inclusions: new InclusionSwitches(false, true, false, false, false),
            ),
        );

        self::assertSame('My name for it', $subscription->getCustomTitle());
        self::assertSame(3, $subscription->getPosition());
        self::assertSame($readUntil, $subscription->getMarkedReadUntil());
        self::assertSame($createdAt, $subscription->getCreatedAt());
        self::assertFalse($subscription->isIncludedIn(InclusionSurface::AllItems));
        self::assertTrue($subscription->isIncludedIn(InclusionSurface::ForYou));
        self::assertFalse($subscription->isIncludedIn(InclusionSurface::SavedSearches));
        self::assertFalse($subscription->isIncludedIn(InclusionSurface::Trending));
        self::assertFalse($subscription->isIncludedIn(InclusionSurface::YourStream));
    }

    private function factory(): RestoredFoundationFactory
    {
        return new RestoredFoundationFactory(new TagFactory(), new FeedFactory());
    }

    private function user(): User
    {
        return new User('restorer@example.test', new \DateTimeImmutable('2026-08-01'));
    }
}

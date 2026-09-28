<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Factory;

use App\Entity\Feed;
use App\Entity\User;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Factory\RestoredFoundationFactory;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\Tag\Factory\TagFactory;
use PHPUnit\Framework\TestCase;

final class RestoredFoundationFactoryTest extends TestCase
{
    public function testATagIsRestoredAsTheFileStatesIt(): void
    {
        $tag = $this->factory()->tag($this->user(), new TagLine('Science', '#123456', 'flask', 4));

        self::assertSame('Science', $tag->getName());
        self::assertSame('#123456', $tag->getColor());
        self::assertSame('flask', $tag->getIcon());
        self::assertSame(4, $tag->getPosition());
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
        ));

        self::assertSame('https://restored.example/feed.xml', $feed->getUrl());
        self::assertSame('https://restored.example', $feed->getSiteUrl());
        self::assertSame('Restored', $feed->getTitle());
        self::assertSame('A restored feed', $feed->getDescription());
        self::assertSame('https://restored.example/favicon.ico', $feed->getFaviconUrl());
        self::assertSame('https://restored.example/logo.png', $feed->getImageUrl());
        self::assertSame('scraped', $feed->getSourceFormat());
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
                includeInAllItems: false,
                includeInForYou: false,
            ),
        );

        self::assertSame('My name for it', $subscription->getCustomTitle());
        self::assertSame(3, $subscription->getPosition());
        self::assertSame($readUntil, $subscription->getMarkedReadUntil());
        self::assertSame($createdAt, $subscription->getCreatedAt());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
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

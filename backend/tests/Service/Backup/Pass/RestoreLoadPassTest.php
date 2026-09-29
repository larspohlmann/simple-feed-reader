<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Pass;

use App\Entity\Feed;
use App\Entity\User;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Factory\RestoredFoundationFactory;
use App\Service\Backup\Pass\RestoreLoadPass;
use App\Service\Backup\RestoreFeeds\RestoreFeedsInterface;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\Search\SavedSearchSlug;
use App\Service\Tag\Factory\TagFactory;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Pins the one-query feed lookup, and the flush wrap no file content can reach: pass 1 refuses duplicates, so only a
 * driver failure is left, which a fake EntityManager whose flush() throws stands in for.
 */
final class RestoreLoadPassTest extends TestCase
{
    public function testTheFeedLookupRunsOnceForTheWholeFileAndCreatesWhatItMisses(): void
    {
        $user = new User('one-lookup@example.com', new \DateTimeImmutable('2026-08-01'));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $feeds = $this->createMock(RestoreFeedsInterface::class);
        $feeds->expects($this->once())
            ->method('findByUrlsIndexedByUrl')
            ->with(['https://known.example/feed.xml', 'https://new.example/feed.xml'])
            ->willReturn(['https://known.example/feed.xml' => new Feed('https://known.example/feed.xml')]);
        $pass = new RestoreLoadPass($entityManager, $feeds, $this->savedSearchSlug(), self::rows());

        $result = $pass->run($user, (function () {
            yield $this->feedLine('https://known.example/feed.xml');
            yield $this->feedLine('https://new.example/feed.xml');
            yield $this->subscriptionLine('https://known.example/feed.xml');
            yield $this->subscriptionLine('https://new.example/feed.xml');
        })());

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
    }

    public function testFeedsWithNoSubscriptionAreStillResolvedAtTheFinalFlush(): void
    {
        $user = new User('feeds-only@example.com', new \DateTimeImmutable('2026-08-01'));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $feeds = $this->createMock(RestoreFeedsInterface::class);
        $feeds->expects($this->once())
            ->method('findByUrlsIndexedByUrl')
            ->with(['https://orphan-one.example/feed.xml', 'https://orphan-two.example/feed.xml'])
            ->willReturn([]);
        $pass = new RestoreLoadPass($entityManager, $feeds, $this->savedSearchSlug(), self::rows());

        // No subscription line ever runs, so loadSubscription() never gets a
        // chance to call resolveHeldFeeds() itself — only the final flush can
        // still resolve (and create) these feeds.
        $result = $pass->run($user, (function () {
            yield $this->feedLine('https://orphan-one.example/feed.xml');
            yield $this->feedLine('https://orphan-two.example/feed.xml');
        })());

        self::assertSame(2, $result->feeds);
        self::assertSame(0, $result->subscriptions);
    }

    private function feedLine(string $url): FeedLine
    {
        return new FeedLine(
            url: $url,
            siteUrl: null,
            title: null,
            description: null,
            faviconUrl: null,
            imageUrl: null,
            sourceFormat: 'xml',
        );
    }

    private function subscriptionLine(string $feedUrl): SubscriptionLine
    {
        return new SubscriptionLine(
            feedUrl: $feedUrl,
            customTitle: null,
            position: 0,
            markedReadUntil: null,
            createdAt: new \DateTimeImmutable('2026-07-01 08:00:00'),
            tags: [],
            includeInAllItems: true,
            includeInForYou: true,
        );
    }

    public function testADatabaseFailureDuringTheAccountShapeFlushIsAWrappedBackupError(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($this->dbalException());
        $pass = new RestoreLoadPass(
            $entityManager,
            $this->createStub(RestoreFeedsInterface::class),
            $this->savedSearchSlug(),
            self::rows(),
        );

        $this->expectException(BackupLoadFailedException::class);
        $pass->run(new User('flush-fails@example.com', new \DateTimeImmutable('2026-08-01')), (function () {
            yield from [];
        })());
    }

    private function dbalException(): DbalException
    {
        return new class ('the database rejected a value') extends \Exception implements DbalException {
        };
    }

    /**
     * `final readonly` rules out a mock double, and none of these tests
     * carry a saved-search line anyway — the collaborator never runs, so a
     * real instance merely satisfies the constructor.
     */
    private function savedSearchSlug(): SavedSearchSlug
    {
        return new SavedSearchSlug(new AsciiSlugger());
    }

    private static function rows(): RestoredFoundationFactory
    {
        return new RestoredFoundationFactory(new TagFactory(), new FeedFactory());
    }
}

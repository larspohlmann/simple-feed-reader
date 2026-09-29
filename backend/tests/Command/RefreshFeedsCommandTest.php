<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

final class RefreshFeedsCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:feeds:refresh'));
    }

    /**
     * Subscribed, not just persisted: without --feed, --user or --no-prune the command prunes, and an unsubscribed
     * feed would be swept before the fetcher stub sees it.
     */
    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($feed);
        $subscriber = new User('cli-fixture-subscriber@example.com', new \DateTimeImmutable());
        $this->entityManager->persist($subscriber);
        $this->entityManager->persist(new Subscription($subscriber, $feed, new \DateTimeImmutable()));
        $this->entityManager->flush();

        return $feed;
    }

    public function testRefreshesDueFeedsAndPrintsReport(): void
    {
        $feed = $this->dueFeed('https://cli.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponseModel::notModified($feed->getUrl(), false, null, null));
        // The runner's favicon phase fetches the site homepage through the
        // same fetcher — stub the origin too, or it throws just as loudly.
        $stub->willReturn(
            'https://cli.example.com',
            FetchResponseModel::fetched('https://cli.example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--budget' => '60']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('completed', $display);
        self::assertStringContainsString('notModified', $display);
        // The feed was fetched; a best-effort favicon lookup may also fetch the
        // site homepage through the same client, so assert membership not equality.
        self::assertContains($feed->getUrl(), $stub->fetchedUrls);
    }

    public function testReportsBusyWithoutFailing(): void
    {
        $this->dueFeed('https://cli.example.com/feed');

        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire());

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already in progress', $tester->getDisplay());
        $lock->release();
    }

    public function testAMalformedBudgetIsReportedAndRefused(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $exitCode = $tester->run(['command' => 'app:feeds:refresh', '--budget' => 'not-a-number']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('"not-a-number" is not', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Feed;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshFeedsCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:feeds:refresh'));
    }

    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    public function testRefreshesDueFeedsAndPrintsReport(): void
    {
        $feed = $this->dueFeed('https://cli.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);

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

        /** @var \Symfony\Component\Lock\LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(\Symfony\Component\Lock\LockFactory::class);
        $lock = $lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire());

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already in progress', $tester->getDisplay());
        $lock->release();
    }

    public function testInvalidBudgetIsRejected(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--budget' => 'not-a-number']);

        self::assertSame(Command::INVALID, $exitCode);
    }
}

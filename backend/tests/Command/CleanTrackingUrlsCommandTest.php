<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanTrackingUrlsCommandTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $feed = new Feed('https://example.com/feed');
        $createdAt = new \DateTimeImmutable('2026-11-15 12:00:00');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Entry($feed, 'g-1', 'https://example.com/a?utm_source=rss', 'Title', $createdAt, $createdAt, null),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testDryRunPrintsWhatItWouldClean(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString(
            'Would clean: 1 entries cleaned, 1 hashes changed, 0 cards cleaned, 0 card hashes changed, 0 cards merged',
            self::unwrapped($tester),
        );
        self::assertSame('https://example.com/a?utm_source=rss', $this->storedUrl());
    }

    public function testRunPrintsWhatItCleaned(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(
            '1 entries cleaned, 1 hashes changed, 0 cards cleaned, 0 card hashes changed, 0 cards merged',
            self::unwrapped($tester),
        );
        self::assertStringNotContainsString('Would clean', $tester->getDisplay());
        self::assertSame('https://example.com/a', $this->storedUrl());
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:urls:clean-tracking'));
    }

    private static function unwrapped(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function storedUrl(): mixed
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT url FROM entry WHERE guid = ?', ['g-1']);
    }
}

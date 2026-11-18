<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TrendingExplainCommand;
use App\Entity\Entry;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Enum\FeedLanguage;
use App\Repository\EntryRepository;
use App\Service\Curation\Trending\StoryLinkSource\HeadlineLinks;
use App\Service\Curation\Trending\TrendingExplainer;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TrendingExplainCommandTest extends DbTestCase
{
    public function testPrintsTheGroupsAndNearMissesAndWritesNothing(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $fixtures->hafenstreik($fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $fixtures->hafenstreik($fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $fixtures->hafenstreik($fixtures->feed('gamma'), 2, '2031-05-14 10:00:00');
        $fixtures->entry($fixtures->feed('delta'), 'Vulkan Island', '2031-05-14 08:30:00');
        $fixtures->entry($fixtures->feed('omega'), 'Island Vulkan', '2031-05-14 09:30:00');
        $fixtures->flush();
        $tester = new CommandTester($this->command());

        $tester->execute(['--user' => (string) $fixtures->owner()->requireId(), '--window' => 'day']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('3 feeds', $display);
        self::assertStringContainsString('Hafenstreik legt Hamburg lahm', $display);
        self::assertStringContainsString('shared weight 12.4 < 14', $display);
        self::assertStringContainsString('Island Vulkan', $display);
        self::assertSame(0, $this->entityManager->getRepository(TrendingStory::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(TrendingSnapshot::class)->count([]));
    }

    public function testPrintsHowManyWindowEntriesComeFromFeedsOfEachLanguage(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $german = $fixtures->feed('alpha');
        $german->recordTraits($german->getTraits()->withLanguage(FeedLanguage::German));
        $french = $fixtures->feed('beta');
        $french->recordTraits($french->getTraits()->withLanguage(FeedLanguage::French));
        $fixtures->hafenstreik($german, 0, '2031-05-14 08:00:00');
        $fixtures->hafenstreik($german, 1, '2031-05-14 09:00:00');
        $fixtures->hafenstreik($french, 2, '2031-05-14 10:00:00');
        $fixtures->entry($fixtures->feed('gamma'), 'Vulkan Island', '2031-05-14 08:30:00');
        $fixtures->flush();
        $tester = new CommandTester($this->command());

        $tester->execute(['--user' => (string) $fixtures->owner()->requireId(), '--window' => 'day']);

        self::assertStringContainsString('Languages: de 2, en 0, fr 1, es 0, none 1', $tester->getDisplay());
    }

    public function testAnUnknownWindowFailsNamingTheChoices(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['--user' => '1', '--window' => 'month']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown window. Use one of: day, week.', $tester->getDisplay());
    }

    public function testRejectsAMissingUser(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['--window' => 'day']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--user', $tester->getDisplay());
    }

    private function command(): TrendingExplainCommand
    {
        $entries = $this->entityManager->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        $explainer = new TrendingExplainer(
            TrendingWiring::grouping($this->entityManager, new MockClock('2031-05-14 12:00:00', 'UTC')),
            new HeadlineLinks(),
        );

        return new TrendingExplainCommand($explainer, $entries);
    }
}

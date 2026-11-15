<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ResolveSharedLinksCommand;
use App\Entity\LinkCardContent;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Operations\LinkResolutionBackfill\SharedLinkResolutionBackfill;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkLandingWiring;
use App\Tests\Support\LinkResolutionSweepWiring;
use App\Tests\Support\PostEnrichers;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSharedLinksCommandTest extends DbTestCase
{
    private LinkLandingWiring $wiring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiring = new LinkLandingWiring();
        PostEnrichers::sharedLinks($this->entityManager)->insertMissing([new NewSharedLinkModel(
            (string) new UrlNormalizer()->hash('https://n.pr/a'),
            new LinkCardContent('https://n.pr/a', 'Short', null, null, 'n.pr'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        )]);
    }

    public function testADryRunCountsAndWritesNothing(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));

        self::assertStringContainsString('Would queue 1 short links', $tester->getDisplay());
        self::assertSame(0, $this->resolutionRows());
        self::assertSame([], $this->wiring->requests);
    }

    public function testARunPrintsWhatItResolved(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString(
            'Queued 1; resolved 1, unchanged 0, unresolved 0, deferred 0 in 0.0 s (0.00 s a link)',
            $tester->getDisplay(),
        );
        self::assertSame(1, $this->resolutionRows());
    }

    private function tester(): CommandTester
    {
        $linkResolutions = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $linkResolutions);

        return new CommandTester(new ResolveSharedLinksCommand(new SharedLinkResolutionBackfill(
            $linkResolutions,
            LinkResolutionSweepWiring::build(
                self::getContainer(),
                $this->wiring,
                new MockClock('2026-11-15 12:00:00', 'UTC'),
                [LinkLandingWiring::redirect('https://www.npr.org/a'), LinkLandingWiring::answer(Response::HTTP_OK)],
            ),
        )));
    }

    private function resolutionRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shared_link_resolution');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}

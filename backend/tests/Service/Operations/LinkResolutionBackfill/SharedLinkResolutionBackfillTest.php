<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\LinkResolutionBackfill;

use App\Entity\LinkCardContent;
use App\Entity\SharedLinkResolution;
use App\Enum\LinkResolution;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Operations\LinkResolutionBackfill\Model\LinkResolutionBackfillReportModel;
use App\Service\Operations\LinkResolutionBackfill\SharedLinkResolutionBackfill;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkLandingWiring;
use App\Tests\Support\LinkResolutionSweepWiring;
use App\Tests\Support\PostEnrichers;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SharedLinkResolutionBackfillTest extends DbTestCase
{
    private LinkLandingWiring $wiring;

    public function testOnlyUnqueuedCandidatesAreQueued(): void
    {
        $this->store('https://n.pr/a', 'https://example.com/2026/a-story', 'https://n.pr/b');
        $this->settled('https://n.pr/b', LinkResolution::Unchanged);
        $backfill = $this->backfill([LinkLandingWiring::answer(Response::HTTP_OK)]);

        self::assertSame(1, $backfill->count());
        $report = $backfill->run();

        self::assertEquals(
            new LinkResolutionBackfillReportModel(1, new LinkResolutionReportModel(1, 0, 1, 0, 0, 0.0)),
            $report,
        );
        self::assertSame(LinkResolution::Unchanged, $this->statusOf('https://n.pr/a'));
        self::assertNull($this->statusOf('https://example.com/2026/a-story'));
        self::assertSame(['HEAD https://n.pr/a'], $this->wiring->requests);
    }

    public function testASecondRunQueuesNothing(): void
    {
        $this->store('https://n.pr/a');
        $backfill = $this->backfill([LinkLandingWiring::answer(Response::HTTP_OK)]);
        $backfill->run();

        self::assertSame(0, $backfill->count());
        self::assertEquals(
            new LinkResolutionBackfillReportModel(0, LinkResolutionReportModel::none()),
            $backfill->run(),
        );
    }

    public function testALeftoverPendingRowIsDrained(): void
    {
        $this->store('https://n.pr/a');
        $this->settled('https://n.pr/a', LinkResolution::Pending);

        $report = $this->backfill([LinkLandingWiring::answer(Response::HTTP_OK)])->run();

        self::assertSame(0, $report->queued);
        self::assertSame(1, $report->resolutions->considered);
        self::assertSame(LinkResolution::Unchanged, $this->statusOf('https://n.pr/a'));
    }

    public function testEveryBatchIsWalked(): void
    {
        $this->store('https://n.pr/a', 'https://example.com/2026/a-story', 'https://n.pr/b', 'https://n.pr/c');

        $backfill = $this->backfill([], batchSize: 2);

        self::assertSame(3, $backfill->count());
        self::assertSame(3, $backfill->run()->queued);
    }

    public function testTheDrainRunsUntilNothingIsDue(): void
    {
        $urls = array_map(static fn (int $index): string => 'https://n.pr/a' . $index, range(1, 51));
        $this->store(...$urls);

        $report = $this->backfill([])->run();

        self::assertSame(51, $report->resolutions->considered);
    }

    /** @param list<MockResponse> $responses */
    private function backfill(array $responses, int $batchSize = 500): SharedLinkResolutionBackfill
    {
        $this->wiring = new LinkLandingWiring();

        return new SharedLinkResolutionBackfill(
            $this->linkResolutions(),
            LinkResolutionSweepWiring::build(
                self::getContainer(),
                $this->wiring,
                new MockClock('2026-11-15 12:00:00', 'UTC'),
                $responses,
            ),
            $batchSize,
        );
    }

    private function store(string ...$urls): void
    {
        PostEnrichers::sharedLinks($this->entityManager)->insertMissing(array_map(
            static fn (string $url): NewSharedLinkModel => new NewSharedLinkModel(
                (string) new UrlNormalizer()->hash($url),
                new LinkCardContent($url, 'Title', null, null, 'example.com'),
                null,
                new \DateTimeImmutable('2026-11-15 10:00:00'),
            ),
            array_values($urls),
        ));
    }

    private function settled(string $url, LinkResolution $status): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO shared_link_resolution (shared_link_id, status, attempts)'
            . ' SELECT id, ?, 0 FROM shared_link WHERE url = ?',
            [$status->value, $url],
        );
    }

    private function statusOf(string $url): ?LinkResolution
    {
        $this->entityManager->clear();
        $link = PostEnrichers::sharedLinks($this->entityManager)->findOneBy(['url' => $url]);
        self::assertNotNull($link);

        return $this->entityManager->find(SharedLinkResolution::class, $link->requireId())?->getStatus();
    }

    private function linkResolutions(): SharedLinkResolutionRepository
    {
        $linkResolutions = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $linkResolutions);

        return $linkResolutions;
    }
}

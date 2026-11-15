<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Entity\LinkCardContent;
use App\Entity\SharedLinkResolution;
use App\Enum\LinkResolution;
use App\Service\Operations\Worker\Handler\ResolvePendingLinksHandler;
use App\Service\Operations\Worker\Message\ResolvePendingLinks;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkLandingWiring;
use App\Tests\Support\LinkResolutionSweepWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SharedLinks;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class ResolvePendingLinksHandlerTest extends DbTestCase
{
    public function testFiringResolvesAndLogsTheReport(): void
    {
        $linkId = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://n.pr/a', 'Short', null, null, 'n.pr'),
        )->requireId();
        $logger = new RecordingLogger();
        $handler = new ResolvePendingLinksHandler(
            LinkResolutionSweepWiring::build(
                self::getContainer(),
                new LinkLandingWiring(),
                new MockClock('2026-11-15 12:00:00', 'UTC'),
                [LinkLandingWiring::redirect('https://www.npr.org/a'), LinkLandingWiring::answer(Response::HTTP_OK)],
            ),
            $logger,
        );

        $handler(new ResolvePendingLinks());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Worker link resolution finished.', $logger->records[0]['message']);
        self::assertSame(
            ['considered' => 1, 'resolved' => 1, 'unchanged' => 0, 'unresolved' => 0, 'deferred' => 0, 'seconds' => 0.0],
            $logger->records[0]['context']['report'],
        );
        $this->entityManager->clear();
        self::assertSame(
            LinkResolution::Resolved,
            $this->entityManager->find(SharedLinkResolution::class, $linkId)?->getStatus(),
        );
    }

    public function testAPassFollowsAtMostFiveLinks(): void
    {
        foreach (range(1, 6) as $index) {
            SharedLinks::of(
                $this->entityManager,
                new LinkCardContent('https://n.pr/a' . $index, 'Short', null, null, 'n.pr'),
            );
        }
        $logger = new RecordingLogger();
        $handler = new ResolvePendingLinksHandler(
            LinkResolutionSweepWiring::build(
                self::getContainer(),
                new LinkLandingWiring(),
                new MockClock('2026-11-15 12:00:00', 'UTC'),
            ),
            $logger,
        );

        $handler(new ResolvePendingLinks());

        $report = $logger->records[0]['context']['report'];
        self::assertIsArray($report);
        self::assertSame(5, $report['considered']);
    }
}

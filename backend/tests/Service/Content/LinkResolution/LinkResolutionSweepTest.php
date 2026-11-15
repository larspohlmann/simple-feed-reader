<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkResolution;

use App\Entity\LinkCardContent;
use App\Entity\SharedLinkResolution;
use App\Enum\LinkResolution;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkLandingWiring;
use App\Tests\Support\SharedLinks;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LinkResolutionSweepTest extends DbTestCase
{
    private LinkLandingWiring $wiring;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiring = new LinkLandingWiring();
        $this->clock = new MockClock('2026-11-15 12:00:00');
    }

    public function testPendingRowsAreSettled(): void
    {
        $redirected = $this->linkOf('https://n.pr/a');
        $unchanged = $this->linkOf('https://n.pr/b');

        $report = $this->sweep([
            LinkLandingWiring::redirect('https://www.npr.org/a'),
            LinkLandingWiring::answer(Response::HTTP_OK),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ])->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertEquals(new LinkResolutionReportModel(2, 1, 1, 0, 0, 0.0), $report);
        self::assertSame(LinkResolution::Resolved, $this->stored($redirected)->getStatus());
        self::assertSame('https://www.npr.org/a', $this->stored($redirected)->getResolvedUrl());
        self::assertSame(LinkResolution::Unchanged, $this->stored($unchanged)->getStatus());
    }

    public function testTheBudgetCapsThePass(): void
    {
        $this->linkOf('https://n.pr/a');
        $this->linkOf('https://n.pr/b');
        $last = $this->linkOf('https://n.pr/c');

        $report = $this->sweep([])->resolvePending(LinkResolutionBudgetModel::links(2));

        self::assertSame(2, $report->considered);
        self::assertSame(LinkResolution::Pending, $this->stored($last)->getStatus());
    }

    public function testThePassStopsBeforeALinkThatWouldOverrun(): void
    {
        $this->linkOf('https://n.pr/a');
        $second = $this->linkOf('https://n.pr/b');

        $this->wiring->onRequest = fn () => $this->clock->sleep(6.5);

        $report = $this->sweep([LinkLandingWiring::answer(Response::HTTP_OK)])
            ->resolvePending(LinkResolutionBudgetModel::links(2));

        self::assertSame(1, $report->considered);
        self::assertSame(6.5, $report->seconds);
        self::assertSame(LinkResolution::Pending, $this->stored($second)->getStatus());
    }

    public function testAnEmptyBudgetMakesNoRequest(): void
    {
        $this->linkOf('https://n.pr/a');

        $report = $this->sweep([])->resolvePending(LinkResolutionBudgetModel::links(0));

        self::assertEquals(LinkResolutionReportModel::none(), $report);
        self::assertSame([], $this->wiring->requests);
    }

    public function testATransientFailureIsRetriedAnHourLater(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');

        $report = $this->sweep(self::throttled())->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertSame(1, $report->deferred);
        $stored = $this->stored($linkId);
        self::assertSame(LinkResolution::Pending, $stored->getStatus());
        self::assertSame(1, $stored->getAttempts());
        self::assertEquals(new \DateTimeImmutable('2026-11-15 13:00:00'), $stored->getRetryAt());
    }

    public function testADeferredRowIsNotTriedBeforeItsRetry(): void
    {
        $this->linkOf('https://n.pr/a');
        $sweep = $this->sweep(self::throttled());
        $sweep->resolvePending(LinkResolutionBudgetModel::links(5));
        $this->clock->modify('+59 minutes');

        $report = $sweep->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertSame(0, $report->considered);
    }

    public function testTheThirdTransientFailureIsFinal(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $sweep = $this->sweep(array_merge(self::throttled(), self::throttled(), self::throttled()));

        $sweep->resolvePending(LinkResolutionBudgetModel::links(5));
        $this->clock->modify('+1 hour');
        $second = $sweep->resolvePending(LinkResolutionBudgetModel::links(5));
        $this->clock->modify('+1 hour');
        $third = $sweep->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertSame(1, $second->deferred);
        self::assertSame(1, $third->unresolved);
        $stored = $this->stored($linkId);
        self::assertSame(LinkResolution::Unresolved, $stored->getStatus());
        self::assertSame(3, $stored->getAttempts());
    }

    public function testARetryThatLandsResolves(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $sweep = $this->sweep([
            ...self::throttled(),
            LinkLandingWiring::redirect('https://www.npr.org/a'),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        $sweep->resolvePending(LinkResolutionBudgetModel::links(5));
        $this->clock->modify('+1 hour');
        $sweep->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertSame('https://www.npr.org/a', $this->stored($linkId)->getResolvedUrl());
    }

    public function testARefusalIsFinalAtOnce(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');

        $report = $this->sweep([
            LinkLandingWiring::redirect('https://internal.example/'),
            LinkLandingWiring::redirect('https://internal.example/'),
        ])->resolvePending(LinkResolutionBudgetModel::links(5));

        self::assertSame(1, $report->unresolved);
        self::assertSame(LinkResolution::Unresolved, $this->stored($linkId)->getStatus());
    }

    /** @return list<MockResponse> */
    private static function throttled(): array
    {
        return [
            LinkLandingWiring::answer(Response::HTTP_TOO_MANY_REQUESTS),
            LinkLandingWiring::answer(Response::HTTP_TOO_MANY_REQUESTS),
        ];
    }

    /** @param list<MockResponse> $responses */
    private function sweep(array $responses): LinkResolutionSweep
    {
        $linkResolutions = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $linkResolutions);

        return new LinkResolutionSweep(
            $linkResolutions,
            $this->wiring->landing($responses),
            new NaiveUtcClock($this->clock),
        );
    }

    private function linkOf(string $url): int
    {
        return SharedLinks::of(
            $this->entityManager,
            new LinkCardContent($url, 'Title', null, null, 'example.com'),
        )->requireId();
    }

    private function stored(int $linkId): SharedLinkResolution
    {
        $this->entityManager->clear();
        $stored = $this->entityManager->find(SharedLinkResolution::class, $linkId);
        self::assertInstanceOf(SharedLinkResolution::class, $stored);

        return $stored;
    }
}

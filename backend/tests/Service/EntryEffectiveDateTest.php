<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntryEffectiveDate;
use App\Service\FeedIngestContext;
use PHPUnit\Framework\TestCase;

final class EntryEffectiveDateTest extends TestCase
{
    private const string RUN = '2026-08-14 12:00:00';
    private const string PREVIOUS_RUN = '2026-08-14 06:00:00';

    public function testFirstFetchKeepsThePublishedDate(): void
    {
        self::assertSame(
            '2026-05-01 09:00:00',
            $this->resolve('2026-05-01 09:00:00', self::firstFetch()),
        );
    }

    public function testFirstFetchWithoutAPublishedDateUsesTheFetchInstant(): void
    {
        self::assertSame(self::RUN, $this->resolve(null, self::firstFetch()));
    }

    public function testRefreshWithoutAPublishedDateUsesTheFetchInstant(): void
    {
        self::assertSame(self::RUN, $this->resolve(null, self::refresh()));
    }

    public function testAnArticlePublishedSinceTheLastFetchArrivesAtTheFetchInstant(): void
    {
        self::assertSame(self::RUN, $this->resolve('2026-08-14 07:30:00', self::refresh()));
    }

    public function testAnArticlePublishedBeforeTheLastFetchKeepsItsPublishedDate(): void
    {
        self::assertSame(
            '2020-03-01 00:00:00',
            $this->resolve('2020-03-01 00:00:00', self::refresh()),
        );
    }

    /**
     * The grace runs up to the previous fetch, not past it: an article stamped
     * at that exact instant was not published since we last looked.
     */
    public function testAnArticleStampedAtTheLastFetchArrivesAtTheFetchInstant(): void
    {
        self::assertSame(self::RUN, $this->resolve(self::PREVIOUS_RUN, self::refresh()));
    }

    public function testAFuturePublishedDateNeverOutranksTheFetchInstant(): void
    {
        self::assertSame(self::RUN, $this->resolve('2027-01-01 00:00:00', self::refresh()));
    }

    public function testAFuturePublishedDateIsClampedOnAFirstFetchToo(): void
    {
        self::assertSame(self::RUN, $this->resolve('2027-01-01 00:00:00', self::firstFetch()));
    }

    private function resolve(?string $publishedAt, FeedIngestContext $context): string
    {
        return EntryEffectiveDate::for(
            null === $publishedAt ? null : new \DateTimeImmutable($publishedAt),
            $context,
        )->format('Y-m-d H:i:s');
    }

    private static function firstFetch(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable(self::RUN), null);
    }

    private static function refresh(): FeedIngestContext
    {
        return new FeedIngestContext(
            new \DateTimeImmutable(self::RUN),
            new \DateTimeImmutable(self::PREVIOUS_RUN),
        );
    }
}

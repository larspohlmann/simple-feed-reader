<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Share\Model;

use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Service\Social\Share\Model\ShareFetchPlanModel;
use PHPUnit\Framework\TestCase;

final class ShareFetchPlanModelTest extends TestCase
{
    public function testEveryUrlAndPostedFormIsAFetch(): void
    {
        $plan = new ShareFetchPlanModel(['https://a.example/', 'https://b.example/'], ['https://a.example/' => 'x']);

        self::assertSame(3, $plan->fetches());
    }

    public function testAKnownUrlIsNoFetchAndLosesItsPostedForm(): void
    {
        $plan = new ShareFetchPlanModel(
            ['https://a.example/', 'https://b.example/'],
            ['https://a.example/' => 'https://a.example/?ref=x', 'https://b.example/' => 'https://b.example/?ref=y'],
        );

        $known = $plan->withKnown(['https://a.example/' => self::link()]);

        self::assertSame(2, $known->fetches());
        self::assertSame(['https://b.example/' => 'https://b.example/?ref=y'], $known->postedForms);
        self::assertSame(['https://a.example/', 'https://b.example/'], $known->urls);
        self::assertArrayHasKey('https://a.example/', $known->known);
    }

    public function testAnEntryOfNoPlannedUrlIsNotKnown(): void
    {
        $plan = new ShareFetchPlanModel(['https://a.example/']);

        $known = $plan->withKnown(['https://other.example/' => self::link()]);

        self::assertSame([], $known->known);
        self::assertSame(1, $known->fetches());
    }

    public function testDroppingTheChecksKeepsTheKnownLinks(): void
    {
        $plan = new ShareFetchPlanModel(['https://a.example/', 'https://b.example/'], ['https://b.example/' => 'y'])
            ->withKnown(['https://a.example/' => self::link()]);

        self::assertSame(1, $plan->withoutChecks()->fetches());
        self::assertArrayHasKey('https://a.example/', $plan->withoutChecks()->known);
    }

    private static function link(): SharedLink
    {
        return new SharedLink(
            'hash',
            new LinkCardContent('https://a.example/', 'A', null, null, 'a.example'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
    }
}

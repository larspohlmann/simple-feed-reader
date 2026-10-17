<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts\Pass;

use App\Entity\EntryGuid;
use App\Service\SavedPosts\Pass\SavedCopyPlan;
use PHPUnit\Framework\TestCase;

final class SavedCopyPlanTest extends TestCase
{
    public function testAnUnheldGuidIsCopiedAsIs(): void
    {
        $plan = new SavedCopyPlan([self::original(1, 'a', 'url-a', 10)], []);

        self::assertSame([1], $plan->plain());
        self::assertSame([], $plan->merged());
        self::assertSame([], $plan->renamed());
        self::assertSame([], $plan->deferred());
    }

    public function testTheSameGuidAndUrlMergeAndTwoNullUrlsCountAsTheSame(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a', 'url-a', 10), self::original(2, 'b', null, 10)],
            [
                hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a'],
                hash('sha256', 'b') => ['id' => 71, 'urlHash' => null],
            ],
        );

        self::assertSame([1 => 70, 2 => 71], $plan->merged());
        self::assertSame([], $plan->plain());
    }

    public function testTheSameGuidUnderAnotherUrlIsRenamedForItsFeed(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, '1', 'url-other', 20)],
            [hash('sha256', '1') => ['id' => 70, 'urlHash' => 'url-first']],
        );

        self::assertEquals([1 => new EntryGuid('1#20')], $plan->renamed());
        self::assertSame([], $plan->merged());
    }

    public function testARenamedCopyAlreadyHeldMergesIntoIt(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, '1', 'url-other', 20)],
            [
                hash('sha256', '1') => ['id' => 70, 'urlHash' => 'url-first'],
                hash('sha256', '1#20') => ['id' => 71, 'urlHash' => 'url-other'],
            ],
        );

        self::assertSame([1 => 71], $plan->merged());
        self::assertSame([], $plan->renamed());
    }

    public function testTwoFeedsHoldingTheSameUnheldGuidCopyOneAndDeferTheOther(): void
    {
        $plan = new SavedCopyPlan([self::original(1, 'a', 'url-a', 10), self::original(2, 'a', 'url-b', 20)], []);

        self::assertSame([1], $plan->plain());
        self::assertSame([2], $plan->deferred());
        self::assertSame([], $plan->renamed());
        self::assertSame([], $plan->merged());
    }

    public function testTwoFeedsRenamingTheSameHeldGuidEachGetTheirOwnName(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a', 'url-b', 10), self::original(2, 'a', 'url-c', 20)],
            [hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a']],
        );

        self::assertEquals([1 => new EntryGuid('a#10'), 2 => new EntryGuid('a#20')], $plan->renamed());
        self::assertSame([], $plan->deferred());
    }

    public function testARenamedGuidAnotherOriginalCarriesAsIsIsDeferred(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a#10', 'url-b', 10), self::original(2, 'a', 'url-b', 10)],
            [hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a']],
        );

        self::assertSame([1], $plan->plain());
        self::assertSame([], $plan->renamed());
        self::assertSame([2], $plan->deferred());
    }

    public function testAPlainGuidAnotherOriginalWasRenamedToIsDeferred(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a', 'url-b', 10), self::original(2, 'a#10', 'url-b', 10)],
            [hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a']],
        );

        self::assertEquals([1 => new EntryGuid('a#10')], $plan->renamed());
        self::assertSame([], $plan->plain());
        self::assertSame([2], $plan->deferred());
    }

    public function testTwoOriginalsMergingIntoOneHeldCopyAreNotDeferred(): void
    {
        $plan = new SavedCopyPlan(
            [self::original(1, 'a', 'url-a', 10), self::original(2, 'a', 'url-a', 20)],
            [hash('sha256', 'a') => ['id' => 70, 'urlHash' => 'url-a']],
        );

        self::assertSame([1 => 70, 2 => 70], $plan->merged());
        self::assertSame([], $plan->deferred());
    }

    public function testItAsksForTheGuidAndTheRenamedGuidOfEachOriginal(): void
    {
        self::assertSame(
            [hash('sha256', 'a'), hash('sha256', 'a#10')],
            SavedCopyPlan::heldHashesToAsk([self::original(1, 'a', null, 10)]),
        );
    }

    /** @return array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int} */
    private static function original(int $id, string $guid, ?string $urlHash, int $feedId): array
    {
        return [
            'id' => $id,
            'guid' => $guid,
            'guidHash' => hash('sha256', $guid),
            'urlHash' => $urlHash,
            'feedId' => $feedId,
        ];
    }
}

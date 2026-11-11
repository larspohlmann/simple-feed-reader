<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SharingSettings;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharingSettingsTest extends TestCase
{
    public function testSharingIsOnWithNothingHiddenAndSharesRememberedByDefault(): void
    {
        $settings = UnsavedUserSettings::sharing();

        self::assertTrue($settings->isEnabled());
        self::assertSame([], $settings->getHiddenTargets());
        self::assertTrue($settings->isRememberingShares());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $settings = UnsavedUserSettings::sharing();

        $settings->setEnabled(false);
        $settings->setHiddenTargets(['facebook', 'x']);
        $settings->setRememberingShares(false);

        self::assertFalse($settings->isEnabled());
        self::assertSame(['facebook', 'x'], $settings->getHiddenTargets());
        self::assertFalse($settings->isRememberingShares());
    }

    public function testMalformedAndRepeatedTargetIdsAreDropped(): void
    {
        $settings = UnsavedUserSettings::sharing();

        $settings->setHiddenTargets(['x', 'Not An Id', 'x', '', 'reddit']);

        self::assertSame(['x', 'reddit'], $settings->getHiddenTargets());
    }

    public function testOnlyTheFirstMaximumOfHiddenTargetsIsKept(): void
    {
        $settings = UnsavedUserSettings::sharing();
        $ids = array_map(
            static fn (int $number): string => 'target-' . $number,
            range(1, SharingSettings::MAX_HIDDEN_TARGETS + 1),
        );

        $settings->setHiddenTargets($ids);

        $expected = \array_slice($ids, 0, SharingSettings::MAX_HIDDEN_TARGETS);
        self::assertSame($expected, $settings->getHiddenTargets());
    }

    #[DataProvider('recordingCases')]
    public function testSharesAreRecordedOnlyWhileSharingIsOnAndRemembered(
        bool $enabled,
        bool $rememberingShares,
        bool $expected,
    ): void {
        $settings = UnsavedUserSettings::sharing();
        $settings->setEnabled($enabled);
        $settings->setRememberingShares($rememberingShares);

        self::assertSame($expected, $settings->recordsShares());
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function recordingCases(): iterable
    {
        yield 'sharing off' => [false, true, false];
        yield 'not remembering' => [true, false, false];
        yield 'both on' => [true, true, true];
    }
}

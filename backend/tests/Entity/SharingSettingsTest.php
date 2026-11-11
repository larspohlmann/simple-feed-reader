<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SharingSettings;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharingSettingsTest extends TestCase
{
    public function testSharingIsOnWithNothingHiddenAndSharesRememberedByDefault(): void
    {
        $settings = $this->newSettings();

        self::assertTrue($settings->isEnabled());
        self::assertSame([], $settings->getHiddenTargets());
        self::assertTrue($settings->isRememberingShares());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $settings = $this->newSettings();

        $settings->setEnabled(false);
        $settings->setHiddenTargets(['facebook', 'x']);
        $settings->setRememberingShares(false);

        self::assertFalse($settings->isEnabled());
        self::assertSame(['facebook', 'x'], $settings->getHiddenTargets());
        self::assertFalse($settings->isRememberingShares());
    }

    public function testMalformedAndRepeatedTargetIdsAreDropped(): void
    {
        $settings = $this->newSettings();

        $settings->setHiddenTargets(['x', 'Not An Id', 'x', '', 'reddit']);

        self::assertSame(['x', 'reddit'], $settings->getHiddenTargets());
    }

    public function testOnlyTheFirstMaximumOfHiddenTargetsIsKept(): void
    {
        $settings = $this->newSettings();
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
        $settings = $this->newSettings();
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

    private function newSettings(): SharingSettings
    {
        return new SharingSettings(new User('reader@example.com', new \DateTimeImmutable('2026-11-03 09:00:00')));
    }
}

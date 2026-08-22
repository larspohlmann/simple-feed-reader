<?php

declare(strict_types=1);

namespace App\Tests\Service\Version;

use App\Service\Version\SemanticVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemanticVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function upgradeCases(): iterable
    {
        // A prerelease is only lapped when the FINAL release of that version
        // (or higher) is published — the crux of the whole feature.
        yield 'dev build ahead of the latest release is not an upgrade' => ['v1.4.2-dev.3', 'v1.4.1', false];
        yield 'the release laps its own prerelease' => ['v1.4.2-dev.3', 'v1.4.2', true];

        yield 'a newer patch is an upgrade' => ['v1.4.1', 'v1.4.2', true];
        yield 'the same version is not an upgrade' => ['v1.4.2', 'v1.4.2', false];
        yield 'a prerelease is never an upgrade over the release you run' => ['v1.4.2', 'v1.4.2-dev.3', false];

        yield 'a newer major outranks everything' => ['v1.9.9', 'v2.0.0', true];
        yield 'an older major is not an upgrade' => ['v2.0.0', 'v1.9.9', false];
        yield 'a newer minor is an upgrade' => ['v1.4.9', 'v1.5.0', true];

        yield 'a higher dev number is an upgrade' => ['v1.4.2-dev.3', 'v1.4.2-dev.4', true];
        yield 'a lower dev number is not an upgrade' => ['v1.4.2-dev.4', 'v1.4.2-dev.3', false];

        yield 'a missing v prefix still parses' => ['1.4.1', '1.4.2', true];

        // Anything we cannot rank must never claim an update — the silent-absence
        // rule for development builds and malformed tags.
        yield 'a development current build yields no upgrade' => ['dev', 'v1.4.2', false];
        yield 'an unparseable candidate yields no upgrade' => ['v1.4.2', 'garbage', false];
    }

    #[DataProvider('upgradeCases')]
    public function testIsUpgrade(string $current, string $candidate, bool $expected): void
    {
        self::assertSame($expected, SemanticVersion::isUpgrade($current, $candidate));
    }

    public function testTryParseRejectsANonVersionString(): void
    {
        self::assertNull(SemanticVersion::tryParse('dev'));
        self::assertNull(SemanticVersion::tryParse(''));
        self::assertNull(SemanticVersion::tryParse('v1.2'));
    }

    public function testTryParseRejectsAVersionBuriedInOtherText(): void
    {
        // The pattern is anchored at both ends: a version must be the WHOLE
        // string, not a fragment of it.
        self::assertNull(SemanticVersion::tryParse('release 1.2.3'));
        self::assertNull(SemanticVersion::tryParse('1.2.3.4'));
        self::assertNull(SemanticVersion::tryParse('1.2.3-beta'));
    }

    public function testTryParseAcceptsAReleaseAndAPrerelease(): void
    {
        self::assertNotNull(SemanticVersion::tryParse('v1.4.2'));
        self::assertNotNull(SemanticVersion::tryParse('v1.4.2-dev.3'));
    }
}

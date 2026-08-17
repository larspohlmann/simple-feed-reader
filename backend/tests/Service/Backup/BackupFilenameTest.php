<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupFilename;
use App\Service\Version\ReleaseVersion;
use PHPUnit\Framework\TestCase;

final class BackupFilenameTest extends TestCase
{
    private function exportedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-17T09:30:00Z');
    }

    public function testBuildsTheDocumentedExampleForANormalAddress(): void
    {
        $filename = new BackupFilename('ada.lovelace@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-ada-lovelace-at-fastmail-20260817.json.gz',
            $filename->value(),
        );
    }

    public function testEveryCharacterOutsideTheInvariantIsGoneExceptTheSuffixDots(): void
    {
        $filename = new BackupFilename('ada.lovelace@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+\.json\.gz$/', $filename->value());
    }

    public function testReducesAPlusTagInTheLocalPartToASeparator(): void
    {
        $filename = new BackupFilename('ada.lovelace+e2e@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-ada-lovelace-e2e-at-fastmail-20260817.json.gz',
            $filename->value(),
        );
    }

    public function testKeepsOnlyTheFirstDomainLabelNotTheTld(): void
    {
        $filename = new BackupFilename('reader@mail.example.co.uk', 'v0.6.2', $this->exportedAt());

        self::assertStringContainsString('-at-mail-20260817.json.gz', $filename->value());
    }

    public function testUnderscoresDotsInAPreReleaseVersionAndKeepsItsOwnHyphen(): void
    {
        $filename = new BackupFilename('ada.lovelace@fastmail.com', '0.7.0-dev.3', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_7_0-dev_3-ada-lovelace-at-fastmail-20260817.json.gz',
            $filename->value(),
        );
    }

    /**
     * ReleaseVersion::development() is what every local checkout and Docker
     * run reports — no version.json has ever been deployed there. "dev" says
     * so plainly rather than inventing a version number the build never had.
     */
    public function testReportsTheDevelopmentVersionHonestly(): void
    {
        $filename = new BackupFilename(
            'ada.lovelace@fastmail.com',
            ReleaseVersion::development()->version,
            $this->exportedAt(),
        );

        self::assertSame(
            'simplefeedreader-dev-ada-lovelace-at-fastmail-20260817.json.gz',
            $filename->value(),
        );
    }

    public function testCollapsesAnAccentedCharacterRatherThanLeavingItBare(): void
    {
        $filename = new BackupFilename('laŭra@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+\.json\.gz$/', $filename->value());
        self::assertStringContainsString('-l', $filename->value());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Model;

use App\Service\Backup\Model\BackupFilenameModel;
use App\Service\Version\Model\ReleaseVersionModel;
use PHPUnit\Framework\TestCase;

final class BackupFilenameModelTest extends TestCase
{
    private function exportedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-17T09:30:00Z');
    }

    public function testBuildsTheDocumentedExampleForANormalAddress(): void
    {
        $filename = new BackupFilenameModel('ada.lovelace@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-ada-lovelace-at-fastmail-20260817.zip',
            $filename->value(),
        );
    }

    public function testEveryCharacterOutsideTheInvariantIsGoneExceptTheSuffixDots(): void
    {
        $filename = new BackupFilenameModel('ada.lovelace@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+\.zip$/', $filename->value());
    }

    public function testReducesAPlusTagInTheLocalPartToASeparator(): void
    {
        $filename = new BackupFilenameModel('ada.lovelace+e2e@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-ada-lovelace-e2e-at-fastmail-20260817.zip',
            $filename->value(),
        );
    }

    public function testKeepsOnlyTheFirstDomainLabelNotTheTld(): void
    {
        $filename = new BackupFilenameModel('reader@mail.example.co.uk', 'v0.6.2', $this->exportedAt());

        self::assertStringContainsString('-at-mail-20260817.zip', $filename->value());
    }

    public function testUnderscoresDotsInAPreReleaseVersionAndKeepsItsOwnHyphen(): void
    {
        $filename = new BackupFilenameModel('ada.lovelace@fastmail.com', '0.7.0-dev.3', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_7_0-dev_3-ada-lovelace-at-fastmail-20260817.zip',
            $filename->value(),
        );
    }

    /**
     * ReleaseVersionModel::development() is what every local checkout and Docker
     * run reports — no version.json has ever been deployed there. "dev" says
     * so plainly rather than inventing a version number the build never had.
     */
    public function testReportsTheDevelopmentVersionHonestly(): void
    {
        $filename = new BackupFilenameModel(
            'ada.lovelace@fastmail.com',
            ReleaseVersionModel::development()->version,
            $this->exportedAt(),
        );

        self::assertSame(
            'simplefeedreader-dev-ada-lovelace-at-fastmail-20260817.zip',
            $filename->value(),
        );
    }

    public function testCollapsesAnAccentedCharacterRatherThanLeavingItBare(): void
    {
        $filename = new BackupFilenameModel('laŭra@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+\.zip$/', $filename->value());
        self::assertStringContainsString('-l', $filename->value());
    }

    /** Nothing validates an address's format, so one without "@" is reachable and must not leave a trailing "-at-". */
    public function testHandlesAnAddressWithNoAtSignWithoutADoubledOrTrailingSeparator(): void
    {
        $filename = new BackupFilenameModel('notanemail', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-notanemail-at-20260817.zip',
            $filename->value(),
        );
    }

    public function testHandlesAnEmptyLocalPartWithoutALeadingSeparator(): void
    {
        $filename = new BackupFilenameModel('@fastmail.com', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-at-fastmail-20260817.zip',
            $filename->value(),
        );
    }

    public function testHandlesAnEmptyDomainWithoutATrailingSeparator(): void
    {
        $filename = new BackupFilenameModel('user@', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-user-at-20260817.zip',
            $filename->value(),
        );
    }

    public function testHandlesASingleLabelDomainNormally(): void
    {
        $filename = new BackupFilenameModel('user@localhost', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-user-at-localhost-20260817.zip',
            $filename->value(),
        );
    }

    public function testHandlesABareAtSignWithoutAnEmptyAccountField(): void
    {
        $filename = new BackupFilenameModel('@', 'v0.6.2', $this->exportedAt());

        self::assertSame(
            'simplefeedreader-0_6_2-at-20260817.zip',
            $filename->value(),
        );
    }
}

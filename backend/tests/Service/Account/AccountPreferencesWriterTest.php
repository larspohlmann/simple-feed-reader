<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Enum\MagazineStyle;
use App\Enum\SupportedLocale;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Account\Model\SharingSettingsModel;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class AccountPreferencesWriterTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testChangeLocalePersists(): void
    {
        $user = $this->user('prefs-locale@example.com');

        $this->writer()->changeLocale($user, SupportedLocale::GERMAN);

        self::assertSame(SupportedLocale::GERMAN, $this->reload($user)->getLocale());
    }

    public function testChangeScrapeFallbackPersists(): void
    {
        $user = $this->user('prefs-scrape@example.com');
        $wanted = !StoredUserSettings::reading($this->entityManager, $user)->isScrapeFallbackEnabled();

        $this->writer()->changeScrapeFallback($user, $wanted);

        $reloaded = StoredUserSettings::reading($this->entityManager, $this->reload($user));
        self::assertSame($wanted, $reloaded->isScrapeFallbackEnabled());
    }

    public function testChangeMagazineStylePersists(): void
    {
        $user = $this->user('prefs-magazine@example.com');
        $wanted = MagazineStyle::Airy === StoredUserSettings::reading($this->entityManager, $user)->getMagazineStyle()
            ? MagazineStyle::Boxed
            : MagazineStyle::Airy;

        $this->writer()->changeMagazineStyle($user, $wanted);

        $reloaded = StoredUserSettings::reading($this->entityManager, $this->reload($user));
        self::assertSame($wanted, $reloaded->getMagazineStyle());
    }

    public function testChangeSharingPersistsAllThreeValues(): void
    {
        $user = $this->user('prefs-sharing@example.com');

        $this->writer()->changeSharing($user, new SharingSettingsModel(false, ['x', 'Not An Id', 'x'], false));

        $reloaded = StoredUserSettings::sharing($this->entityManager, $this->reload($user));
        self::assertFalse($reloaded->isEnabled());
        self::assertSame(['x'], $reloaded->getHiddenTargets());
        self::assertFalse($reloaded->isRememberingShares());
    }

    public function testChangeTrendingPersists(): void
    {
        $user = $this->user('prefs-trending@example.com');

        $this->writer()->changeTrending($user, false);

        self::assertFalse(StoredUserSettings::reading($this->entityManager, $this->reload($user))->isTrendingEnabled());
    }

    public function testChangeDigestPersistsTheConfiguration(): void
    {
        $user = $this->user('prefs-digest@example.com');

        $this->writer()->changeDigest(
            $user,
            new DigestConfigurationModel(true, DigestCadence::Weekly, 7, 3, DigestFormat::Text),
        );

        $settings = StoredUserSettings::digest($this->entityManager, $this->reload($user));
        self::assertTrue($settings->isEnabled());
        self::assertSame(DigestCadence::Weekly, $settings->getCadence());
        self::assertSame(7, $settings->getSendHour());
        self::assertSame(3, $settings->getWeekday());
        self::assertSame(DigestFormat::Text, $settings->getFormat());
    }

    public function testAnswerPasskeyOfferPersistsTheAnswer(): void
    {
        $user = $this->user('prefs-passkey@example.com');

        $this->writer()->answerPasskeyOffer($user);

        self::assertNotNull($this->reload($user)->getPreferences()->getPasskeyOfferAnsweredAt());
    }

    private function writer(): AccountPreferencesWriter
    {
        $writer = self::getContainer()->get(AccountPreferencesWriter::class);
        self::assertInstanceOf(AccountPreferencesWriter::class, $writer);

        return $writer;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Dto\Me\UpdateDigestRequest;
use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestFormat;
use App\Service\Reader\MagazineStyle;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountPreferencesWriterTest extends DbTestCase
{
    public function testChangeLocalePersists(): void
    {
        $user = $this->user('prefs-locale@example.com');

        $this->writer()->changeLocale($user, new UpdateLocaleRequest(SupportedLocale::GERMAN));

        self::assertSame(SupportedLocale::GERMAN, $this->reload($user)->getLocale());
    }

    public function testChangeScrapeFallbackPersists(): void
    {
        $user = $this->user('prefs-scrape@example.com');
        $wanted = !$user->getPreferences()->isScrapeFallbackEnabled();

        $this->writer()->changeScrapeFallback($user, new UpdatePreferencesRequest($wanted));

        self::assertSame($wanted, $this->reload($user)->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testChangeMagazineStylePersists(): void
    {
        $user = $this->user('prefs-magazine@example.com');
        $wanted = MagazineStyle::Airy === $user->getPreferences()->getMagazineStyle()
            ? MagazineStyle::Boxed
            : MagazineStyle::Airy;

        $this->writer()->changeMagazineStyle($user, new UpdateMagazineStyleRequest($wanted));

        self::assertSame($wanted, $this->reload($user)->getPreferences()->getMagazineStyle());
    }

    public function testChangeDigestPersistsTheConfiguration(): void
    {
        $user = $this->user('prefs-digest@example.com');

        $this->writer()->changeDigest(
            $user,
            new UpdateDigestRequest(true, DigestCadence::Weekly, 7, 3, DigestFormat::Text),
        );

        $preferences = $this->reload($user)->getPreferences();
        self::assertTrue($preferences->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $preferences->getDigestCadence());
        self::assertSame(7, $preferences->getDigestSendHour());
        self::assertSame(3, $preferences->getDigestWeekday());
        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
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

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reload(User $user): User
    {
        $id = $user->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}

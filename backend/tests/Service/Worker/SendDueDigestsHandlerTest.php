<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\PreferencesRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests as SendDueDigestsService;
use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Worker\Handler\SendDueDigestsHandler;
use App\Service\Worker\Message\SendDueDigests;
use App\Tests\DbTestCase;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\SavedSearchMatchFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * `App\Service\Mail\Digest\SendDueDigests` is `final readonly`, so PHPUnit
 * cannot generate a mock double for it (PHP refuses to extend a final
 * class) -- the same constraint `SendDueDigestsTest` works around by
 * building a real instance over stub collaborators. This test does the
 * same and drives it through the handler, asserting the one thing the
 * handler is responsible for: that firing it reaches the mailer. That is
 * an honest proof of the wiring, not a re-encoding of the service's own
 * branch coverage (already pinned by SendDueDigestsTest).
 *
 * DigestEntryFinder now reads the membership table through
 * SavedSearchEntryRepository, which is `final` and cannot be doubled, so a
 * due account's match is a real persisted saved-search member (#1116).
 */
final class SendDueDigestsHandlerTest extends DbTestCase
{
    private const string NOW = '2026-08-28T09:30:00Z';

    public function testFiringWithNoDueAccountsCompletesWithoutThrowing(): void
    {
        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($preferences, $mailer)->__invoke(new SendDueDigests());

        $this->addToAssertionCount(1);
    }

    public function testFiringSendsTheDigestForADueVerifiedAccount(): void
    {
        $user = $this->user();
        $prefs = $this->duePreferences($user);
        $search = (new SavedSearchMatchFixture($this->em))
            ->oneMatch($user, 'rust', new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $savedSearches = $this->createStub(SavedSearchRepository::class);
        $savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([$prefs]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::once())->method('send')->with($user, self::isInstanceOf(DigestModel::class));

        $this->handler($preferences, $mailer, $savedSearches)->__invoke(new SendDueDigests());
    }

    private function handler(
        PreferencesRepository&Stub $preferences,
        DigestMailerInterface $mailer,
        ?SavedSearchRepository $savedSearches = null,
    ): SendDueDigestsHandler {
        $service = new SendDueDigestsService(
            $preferences,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $savedSearches ?? $this->createStub(SavedSearchRepository::class),
                new DigestEntryFinder($this->members(), $this->entries()),
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            ),
            $mailer,
            $this->mailCapabilityEnabled(),
            new MockClock(self::NOW),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new InMemoryMailFailureRecorder(),
        );

        return new SendDueDigestsHandler($service, new NullLogger());
    }

    private function mailCapabilityEnabled(): MailCapability
    {
        $settings = $this->createStub(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
    }

    private function user(): User
    {
        $email = 'digest-' . uniqid('', true) . '@example.com';
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }

    /** Daily cadence, send hour 8, so at NOW (09:30) the occurrence is 08:00 today. */
    private function duePreferences(User $user): Preferences
    {
        $prefs = $user->getPreferences();
        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence(DigestCadence::Daily);
        $prefs->setDigestSendHour(8);
        $prefs->setDigestLastSentAt(null);

        return $prefs;
    }

    private function members(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }

    private function entries(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }
}

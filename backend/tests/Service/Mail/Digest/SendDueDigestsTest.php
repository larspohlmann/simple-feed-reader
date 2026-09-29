<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Enum\DigestCadence;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SavedSearchMatchFixture;
use App\Tests\Support\SeedsDigestReaders;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The sweep is the digest's security boundary, because a stale row can outlive the state that made it valid. With the
 * real schedule and composer, digestLastSentAt advances only on a real send: never on an empty compose, an unverified
 * or a not-yet-due account.
 */
final class SendDueDigestsTest extends DbTestCase
{
    use SeedsDigestReaders;

    private const string NOW = '2026-08-28T09:30:00Z';
    private const string OCCURRENCE = '2026-08-28T08:00:00Z';

    private DigestSavedSearchesInterface&Stub $savedSearches;
    private DigestRecipientsInterface&Stub $recipients;
    private DigestMailerInterface&MockObject $mailer;
    private EntityManagerInterface&Stub $entityManagerStub;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = $this->createStub(DigestSavedSearchesInterface::class);
        $this->recipients = $this->createStub(DigestRecipientsInterface::class);
        $this->mailer = $this->createMock(DigestMailerInterface::class);
        $this->entityManagerStub = $this->createStub(EntityManagerInterface::class);
        $this->logger = new RecordingLogger();
    }

    public function testADueUserWithMatchesIsSentAndTheMarkerAdvancesToTheOccurrence(): void
    {
        $user = $this->verifiedUser();
        $preferences = $this->duePreferences($user, lastSentAt: null);
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);

        $this->mailer->expects($this->once())->method('send')
            ->with($user, self::isInstanceOf(DigestModel::class));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $report = $this->sweep(entityManager: $entityManager)->run();

        self::assertSame(1, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $preferences->getDigestLastSentAt());
    }

    /**
     * The composer searches from the last send, not from the new occurrence: an entry that landed after the last
     * digest but before today's occurrence is still new to the reader.
     */
    public function testSinceIsTheLastSendNotJustTheOccurrenceThatBecameDue(): void
    {
        $user = $this->verifiedUser();
        $preferences = $this->duePreferences($user, lastSentAt: new \DateTimeImmutable('2026-08-27T08:00:00Z'));
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-27T20:00:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);

        $this->mailer->expects($this->once())->method('send')
            ->with($user, self::isInstanceOf(DigestModel::class));

        $report = $this->sweep()->run();

        self::assertSame(1, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
    }

    public function testAUserAlreadySentThisPeriodIsNotDueAndIsNotSent(): void
    {
        $user = $this->verifiedUser();
        // digestLastSentAt already sits at the current occurrence: the next
        // occurrence has not arrived yet, so nothing should go out.
        $preferences = $this->duePreferences($user, lastSentAt: new \DateTimeImmutable(self::OCCURRENCE));
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);

        $this->mailer->expects($this->never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $preferences->getDigestLastSentAt());
    }

    public function testADueUserWithNoMatchesIsCountedAsSkippedEmptyAndTheMarkerStaysPut(): void
    {
        $user = $this->verifiedUser();
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $preferences = $this->duePreferences($user, lastSentAt: $seededAt);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);

        $this->mailer->expects($this->never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(1, $report->skippedEmpty);
        self::assertEquals($seededAt, $preferences->getDigestLastSentAt());
    }

    public function testADueButUnverifiedUserIsSkippedAndNotCountedAsSent(): void
    {
        $user = $this->unverifiedUser();
        $preferences = $this->duePreferences($user, lastSentAt: null);
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);

        $this->mailer->expects($this->never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertNull($preferences->getDigestLastSentAt());
    }

    public function testMailDisabledGloballyShortCircuitsWithoutTouchingAnyPreferences(): void
    {
        $recipients = $this->createMock(DigestRecipientsInterface::class);
        $recipients->expects($this->never())->method('findWithDigestEnabled');
        $this->mailer->expects($this->never())->method('send');

        $report = $this->sweep(mailEnabled: false, recipients: $recipients)->run();

        self::assertSame(0, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
    }

    public function testOneDueAndOneNotDueUserAreBothConsideredButOnlyTheDueOneIsSent(): void
    {
        $dueUser = $this->verifiedUser();
        $duePreferences = $this->duePreferences($dueUser, lastSentAt: null);

        $notDueUser = $this->verifiedUser();
        $notDuePreferences = $this->duePreferences($notDueUser, lastSentAt: new \DateTimeImmutable(self::OCCURRENCE));

        $search = $this->givenOneMatch($dueUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$duePreferences, $notDuePreferences]);

        $this->mailer->expects($this->once())->method('send')
            ->with($dueUser, self::isInstanceOf(DigestModel::class));

        $report = $this->sweep()->run();

        self::assertSame(2, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $duePreferences->getDigestLastSentAt());
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $notDuePreferences->getDigestLastSentAt());
    }

    public function testAFailingSendForOneUserDoesNotStarveTheRestOfTheSweep(): void
    {
        $failingUser = $this->verifiedUser();
        $failingPreferences = $this->duePreferences($failingUser, lastSentAt: null);

        $healthyUser = $this->verifiedUser();
        $healthyPreferences = $this->duePreferences($healthyUser, lastSentAt: null);

        $failingSearch = $this->givenOneMatch($failingUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $healthySearch = $this->givenOneMatch($healthyUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturnMap([
            [$failingUser->requireId(), [$failingSearch]],
            [$healthyUser->requireId(), [$healthySearch]],
        ]);
        $this->recipients->method('findWithDigestEnabled')
            ->willReturn([$failingPreferences, $healthyPreferences]);

        $this->mailer->expects($this->exactly(2))->method('send')
            ->willReturnCallback(static function (User $user) use ($failingUser): void {
                if ($user === $failingUser) {
                    throw new TransportException('relay rejected the recipient');
                }
            });

        $report = $this->sweep()->run();

        self::assertSame(2, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertNull($failingPreferences->getDigestLastSentAt());
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $healthyPreferences->getDigestLastSentAt());
    }

    public function testAFailedSendIsLoggedWithTheRecipientAndTheTransportException(): void
    {
        $user = $this->verifiedUser();
        $preferences = $this->duePreferences($user, lastSentAt: null);
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$preferences]);
        $failure = new TransportException('relay rejected the recipient');
        $this->mailer->expects($this->once())->method('send')->willThrowException($failure);

        $this->sweep()->run();

        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame('Digest send failed: {userId} <{email}>', $this->logger->records[0]['message']);
        self::assertSame(
            ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $failure],
            $this->logger->records[0]['context'],
        );
    }

    private function sweep(
        bool $mailEnabled = true,
        ?EntityManagerInterface $entityManager = null,
        ?DigestRecipientsInterface $recipients = null,
    ): SendDueDigests {
        return new SendDueDigests(
            $recipients ?? $this->recipients,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->members(), $this->entries()),
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            ),
            $this->mailer,
            $this->mailCapability($mailEnabled),
            new MockClock(self::NOW),
            $entityManager ?? $this->entityManagerStub,
            $this->logger,
            new InMemoryMailFailureRecorder(),
        );
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }

    /** Daily cadence, send hour 8, so at NOW (09:30) the occurrence is 08:00 today. */
    private function duePreferences(User $user, ?\DateTimeImmutable $lastSentAt): Preferences
    {
        $preferences = $user->getPreferences();
        $preferences->setDigestEnabled(true);
        $preferences->setDigestCadence(DigestCadence::Daily);
        $preferences->setDigestSendHour(8);
        $preferences->setDigestLastSentAt($lastSentAt);

        return $preferences;
    }

    private function givenOneMatch(User $user, \DateTimeImmutable $effectiveDate): SavedSearch
    {
        return (new SavedSearchMatchFixture($this->entityManager))->oneMatch($user, 'rust', $effectiveDate);
    }

    private function members(): SavedSearchEntryRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        return $repository;
    }

    private function entries(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }
}

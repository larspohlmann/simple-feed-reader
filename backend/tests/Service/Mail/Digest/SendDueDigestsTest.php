<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Entity\Preferences;
use App\Entity\SavedSearch;
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
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use App\Tests\DbTestCase;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\SavedSearchMatchFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The sweep is the actual security boundary for the digest (#636): the
 * settings UI already gates enablement and verification, but a stale row can
 * outlive the state that made it valid, so this test drives the real
 * DigestSchedule and DigestComposer rather than mocking the dueness maths
 * away, and pins the three branches that decide whether digestLastSentAt
 * moves: advance only on a real send, never on an empty compose, never on an
 * unverified or not-yet-due account.
 *
 * DigestEntryFinder now reads the membership table through
 * SavedSearchEntryRepository, which is `final` and cannot be doubled, so a
 * "due user with matches" is a real persisted saved-search member (#1116).
 */
final class SendDueDigestsTest extends DbTestCase
{
    private const string NOW = '2026-08-28T09:30:00Z';
    private const string OCCURRENCE = '2026-08-28T08:00:00Z';

    private SavedSearchRepository&Stub $savedSearches;
    private PreferencesRepository&Stub $preferencesRepository;
    private DigestMailerInterface&MockObject $mailer;
    private EntityManagerInterface&Stub $emStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);
        $this->preferencesRepository = $this->createStub(PreferencesRepository::class);
        $this->mailer = $this->createMock(DigestMailerInterface::class);
        $this->emStub = $this->createStub(EntityManagerInterface::class);
    }

    public function testADueUserWithMatchesIsSentAndTheMarkerAdvancesToTheOccurrence(): void
    {
        $user = $this->user();
        $prefs = $this->duePreferences($user, lastSentAt: null);
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $this->mailer->expects(self::once())->method('send')
            ->with($user, self::isInstanceOf(DigestModel::class));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $report = $this->sweep(em: $em)->run();

        self::assertSame(1, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $prefs->getDigestLastSentAt());
    }

    /**
     * The composer must search from the last send, not from the occurrence
     * that just became due: an entry that landed the day before, after the
     * last digest went out, is new to the reader and must be reported even
     * though it predates today's occurrence.
     */
    public function testSinceIsTheLastSendNotJustTheOccurrenceThatBecameDue(): void
    {
        $user = $this->user();
        $prefs = $this->duePreferences($user, lastSentAt: new \DateTimeImmutable('2026-08-27T08:00:00Z'));
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-27T20:00:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $this->mailer->expects(self::once())->method('send')
            ->with($user, self::isInstanceOf(DigestModel::class));

        $report = $this->sweep()->run();

        self::assertSame(1, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
    }

    public function testAUserAlreadySentThisPeriodIsNotDueAndIsNotSent(): void
    {
        $user = $this->user();
        // digestLastSentAt already sits at the current occurrence: the next
        // occurrence has not arrived yet, so nothing should go out.
        $prefs = $this->duePreferences($user, lastSentAt: new \DateTimeImmutable(self::OCCURRENCE));
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $this->mailer->expects(self::never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $prefs->getDigestLastSentAt());
    }

    public function testADueUserWithNoMatchesIsCountedAsSkippedEmptyAndTheMarkerStaysPut(): void
    {
        $user = $this->user();
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $prefs = $this->duePreferences($user, lastSentAt: $seededAt);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([]);
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $this->mailer->expects(self::never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(1, $report->skippedEmpty);
        self::assertEquals($seededAt, $prefs->getDigestLastSentAt());
    }

    public function testADueButUnverifiedUserIsSkippedAndNotCountedAsSent(): void
    {
        $user = $this->user(verified: false);
        $prefs = $this->duePreferences($user, lastSentAt: null);
        $search = $this->givenOneMatch($user, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $this->mailer->expects(self::never())->method('send');

        $report = $this->sweep()->run();

        self::assertSame(1, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
        self::assertNull($prefs->getDigestLastSentAt());
    }

    public function testMailDisabledGloballyShortCircuitsWithoutTouchingAnyPreferences(): void
    {
        $preferencesRepository = $this->createMock(PreferencesRepository::class);
        $preferencesRepository->expects(self::never())->method('findWithDigestEnabled');
        $this->mailer->expects(self::never())->method('send');

        $report = $this->sweep(mailEnabled: false, preferencesRepository: $preferencesRepository)->run();

        self::assertSame(0, $report->considered);
        self::assertSame(0, $report->sent);
        self::assertSame(0, $report->skippedEmpty);
    }

    public function testOneDueAndOneNotDueUserAreBothConsideredButOnlyTheDueOneIsSent(): void
    {
        $dueUser = $this->user();
        $duePrefs = $this->duePreferences($dueUser, lastSentAt: null);

        $notDueUser = $this->user();
        $notDuePrefs = $this->duePreferences($notDueUser, lastSentAt: new \DateTimeImmutable(self::OCCURRENCE));

        $search = $this->givenOneMatch($dueUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$duePrefs, $notDuePrefs]);

        $this->mailer->expects(self::once())->method('send')
            ->with($dueUser, self::isInstanceOf(DigestModel::class));

        $report = $this->sweep()->run();

        self::assertSame(2, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $duePrefs->getDigestLastSentAt());
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $notDuePrefs->getDigestLastSentAt());
    }

    public function testAFailingSendForOneUserDoesNotStarveTheRestOfTheSweep(): void
    {
        $failingUser = $this->user();
        $failingPrefs = $this->duePreferences($failingUser, lastSentAt: null);

        $healthyUser = $this->user();
        $healthyPrefs = $this->duePreferences($healthyUser, lastSentAt: null);

        $failingSearch = $this->givenOneMatch($failingUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $healthySearch = $this->givenOneMatch($healthyUser, new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturnMap([
            [(int) $failingUser->getId(), [$failingSearch]],
            [(int) $healthyUser->getId(), [$healthySearch]],
        ]);
        $this->preferencesRepository->method('findWithDigestEnabled')
            ->willReturn([$failingPrefs, $healthyPrefs]);

        $this->mailer->expects(self::exactly(2))->method('send')
            ->willReturnCallback(static function (User $user) use ($failingUser): void {
                if ($user === $failingUser) {
                    throw new TransportException('relay rejected the recipient');
                }
            });

        $report = $this->sweep()->run();

        self::assertSame(2, $report->considered);
        self::assertSame(1, $report->sent);
        self::assertNull($failingPrefs->getDigestLastSentAt());
        self::assertEquals(new \DateTimeImmutable(self::OCCURRENCE), $healthyPrefs->getDigestLastSentAt());
    }

    private function sweep(
        bool $mailEnabled = true,
        ?EntityManagerInterface $em = null,
        ?PreferencesRepository $preferencesRepository = null,
    ): SendDueDigests {
        return new SendDueDigests(
            $preferencesRepository ?? $this->preferencesRepository,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->members(), $this->entries()),
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            ),
            $this->mailer,
            $this->mailCapability($mailEnabled),
            new MockClock(self::NOW),
            $em ?? $this->emStub,
            new NullLogger(),
            new InMemoryMailFailureRecorder(),
        );
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createStub(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }

    private function user(bool $verified = true): User
    {
        $email = \sprintf('digest-%s@example.com', uniqid('', true));
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        }

        return $user;
    }

    /** Daily cadence, send hour 8, so at NOW (09:30) the occurrence is 08:00 today. */
    private function duePreferences(User $user, ?\DateTimeImmutable $lastSentAt): Preferences
    {
        $prefs = $user->getPreferences();
        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence(DigestCadence::Daily);
        $prefs->setDigestSendHour(8);
        $prefs->setDigestLastSentAt($lastSentAt);

        return $prefs;
    }

    private function givenOneMatch(User $user, \DateTimeImmutable $effectiveDate): SavedSearch
    {
        return (new SavedSearchMatchFixture($this->em))->oneMatch($user, 'rust', $effectiveDate);
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

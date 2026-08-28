<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\PreferencesRepository;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
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
 */
final class SendDueDigestsTest extends TestCase
{
    private const string NOW = '2026-08-28T09:30:00Z';
    private const string OCCURRENCE = '2026-08-28T08:00:00Z';

    private SavedSearchRepository&Stub $savedSearches;
    private EntryListRepository&Stub $entries;
    private PreferencesRepository&Stub $preferencesRepository;
    private DigestMailerInterface&MockObject $mailer;
    private EntityManagerInterface&Stub $em;
    private int $nextUserId = 1;

    protected function setUp(): void
    {
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);
        $this->entries = $this->createStub(EntryListRepository::class);
        $this->preferencesRepository = $this->createStub(PreferencesRepository::class);
        $this->mailer = $this->createMock(DigestMailerInterface::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
    }

    public function testADueUserWithMatchesIsSentAndTheMarkerAdvancesToTheOccurrence(): void
    {
        $user = $this->user();
        $prefs = $this->duePreferences($user, lastSentAt: null);
        $this->givenOneMatch();
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
        $this->givenOneMatch();
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

        $this->givenOneMatch();
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

        $this->givenOneMatch();
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
                new DigestEntryFinder($this->entries),
                new DigestLinkBuilder('https://reader.example'),
            ),
            $this->mailer,
            new MailCapability($mailEnabled ? '' : '1'),
            new MockClock(self::NOW),
            $em ?? $this->em,
            new NullLogger(),
        );
    }

    private function user(bool $verified = true): User
    {
        $email = \sprintf('digest-%d@example.com', $this->nextUserId);
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        new \ReflectionProperty(User::class, 'id')->setValue($user, $this->nextUserId);
        ++$this->nextUserId;

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

    private function givenOneMatch(): void
    {
        $search = new SavedSearch(new User('search-owner@example.com', new \DateTimeImmutable()), 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->entries->method('unreadMatchIdsSince')->willReturn([1]);
        $this->entries->method('rowsByIdsForUser')->willReturn([$this->row(1)]);
    }

    private function row(int $id): EntryListRow
    {
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'guid-' . $id,
            'https://example.com/' . $id,
            'Title ' . $id,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-27T00:00:00Z'),
        );
        new \ReflectionProperty(Entry::class, 'id')->setValue($entry, $id);

        return new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: 'Feed',
            isHidden: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            viewedAt: null,
            markedReadUntil: null,
        );
    }
}

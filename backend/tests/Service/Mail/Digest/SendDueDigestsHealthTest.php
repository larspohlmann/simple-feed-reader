<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\MailKind;
use App\Entity\Preferences;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\MailSendFailureRepository;
use App\Repository\PreferencesRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailDeliveryHealth;
use App\Service\Mail\Settings\MailSettings;
use App\Tests\DbTestCase;
use App\Tests\Support\FixedPublicBaseUrl;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Drives the real catch/success branches of SendDueDigests::sendAndAdvance()
 * against a real MailDeliveryHealth and MailSendFailureRepository (#882), so
 * the digest send path is proven to actually persist and clear failure rows,
 * not just call a mock.
 */
final class SendDueDigestsHealthTest extends DbTestCase
{
    private const string NOW = '2026-08-28T09:30:00Z';

    private SavedSearchRepository&Stub $savedSearches;
    private EntryListRepository&Stub $entries;
    private PreferencesRepository&Stub $preferencesRepository;
    private MailSendFailureRepository $failures;
    private MailDeliveryHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);
        $this->entries = $this->createStub(EntryListRepository::class);
        $this->preferencesRepository = $this->createStub(PreferencesRepository::class);

        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $this->health = $health;
    }

    public function testAFailedDigestSendIsRecorded(): void
    {
        $user = $this->user();
        $prefs = $this->duePreferences($user, lastSentAt: null);
        $this->givenOneMatch();
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $mailer = $this->createStub(DigestMailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP is down'));

        $this->sweep($mailer)->run();

        self::assertSame(1, $this->failures->countAll());
        self::assertSame($user->getEmail(), $this->failures->recent(1)[0]->getRecipient());
        self::assertSame('SMTP is down', $this->failures->recent(1)[0]->getErrorDetail());
    }

    public function testASuccessfulDigestSendClearsPriorFailures(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'old@example.test', 'earlier outage');

        $user = $this->user();
        $prefs = $this->duePreferences($user, lastSentAt: null);
        $this->givenOneMatch();
        $this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$prefs]);

        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $this->sweep($mailer)->run();

        self::assertSame(0, $this->failures->countAll());
    }

    private function sweep(DigestMailerInterface $mailer): SendDueDigests
    {
        return new SendDueDigests(
            $this->preferencesRepository,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->entries),
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            ),
            $mailer,
            $this->mailCapability(),
            new MockClock(self::NOW),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $this->health,
        );
    }

    private function mailCapability(): MailCapability
    {
        $settings = $this->createStub(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
    }

    private function user(): User
    {
        $user = new User('reader@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        new \ReflectionProperty(User::class, 'id')->setValue($user, 1);
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

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
            subscription: new EntryListRowSubscription(1, 'Feed'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            viewedAt: null,
            markedReadUntil: null,
        );
    }
}

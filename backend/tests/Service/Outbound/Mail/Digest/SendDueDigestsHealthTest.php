<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail\Digest;

use App\Entity\DigestSettings;
use App\Entity\User;
use App\Enum\DigestCadence;
use App\Enum\MailKind;
use App\Repository\EntryListRepository;
use App\Repository\MailSendFailureRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Outbound\Mail\Digest\DigestComposer;
use App\Service\Outbound\Mail\Digest\DigestEntryFinder;
use App\Service\Outbound\Mail\Digest\DigestLinkBuilder;
use App\Service\Outbound\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Outbound\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Outbound\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface;
use App\Service\Outbound\Mail\Digest\DigestSchedule;
use App\Service\Outbound\Mail\Digest\SendDueDigests;
use App\Service\Outbound\Mail\MailCapability;
use App\Service\Outbound\Mail\MailFailureRecorder\MailDeliveryHealth;
use App\Service\Outbound\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\SavedSearchMatchFixture;
use App\Tests\Support\SeedsDigestReaders;
use App\Tests\Support\StoredUserSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Drives SendDueDigests::sendAndAdvance()'s catch and success branches against the real MailDeliveryHealth and its
 * repository, so the digest path is proven to persist and clear failure rows.
 */
final class SendDueDigestsHealthTest extends DbTestCase
{
    use SeedsDigestReaders;

    private const string NOW = '2026-08-28T09:30:00Z';

    private DigestSavedSearchesInterface&Stub $savedSearches;
    private DigestRecipientsInterface&Stub $recipients;
    private MailSendFailureRepository $failures;
    private MailDeliveryHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = $this->createStub(DigestSavedSearchesInterface::class);
        $this->recipients = $this->createStub(DigestRecipientsInterface::class);

        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $this->health = $health;
    }

    public function testAFailedDigestSendIsRecorded(): void
    {
        $user = $this->verifiedUser();
        $settings = $this->dueSettings($user, lastSentAt: null);
        $this->givenOneMatch($user);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$settings]);

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

        $user = $this->verifiedUser();
        $settings = $this->dueSettings($user, lastSentAt: null);
        $this->givenOneMatch($user);
        $this->recipients->method('findWithDigestEnabled')->willReturn([$settings]);

        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $this->sweep($mailer)->run();

        self::assertSame(0, $this->failures->countAll());
    }

    private function sweep(DigestMailerInterface $mailer): SendDueDigests
    {
        return new SendDueDigests(
            $this->recipients,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->members(), $this->entries()),
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
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
    }

    /** Daily cadence, send hour 8, so at NOW (09:30) the occurrence is 08:00 today. */
    private function dueSettings(User $user, ?\DateTimeImmutable $lastSentAt): DigestSettings
    {
        $settings = StoredUserSettings::digest($this->entityManager, $user);
        $settings->setEnabled(true);
        $settings->setCadence(DigestCadence::Daily);
        $settings->setSendHour(8);
        $settings->setLastSentAt($lastSentAt);

        return $settings;
    }

    private function givenOneMatch(User $user): void
    {
        $search = (new SavedSearchMatchFixture($this->entityManager))
            ->oneMatch($user, 'rust', new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
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

<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\Preferences;
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
use App\Service\Mail\Digest\SendDueDigests as SendDueDigestsService;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Worker\Handler\SendDueDigestsHandler;
use App\Service\Worker\Message\SendDueDigests;
use App\Tests\DbTestCase;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SavedSearchMatchFixture;
use App\Tests\Support\SeedsDigestReaders;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** SendDueDigests and SavedSearchEntryRepository are final: the service is built for real over stubs. */
final class SendDueDigestsHandlerTest extends DbTestCase
{
    use SeedsDigestReaders;

    private const string NOW = '2026-08-28T09:30:00Z';

    public function testFiringWithNoDueAccountsCompletesWithoutThrowing(): void
    {
        $recipients = $this->createStub(DigestRecipientsInterface::class);
        $recipients->method('findWithDigestEnabled')->willReturn([]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->handler($recipients, $mailer)->__invoke(new SendDueDigests());

        $this->addToAssertionCount(1);
    }

    public function testFiringSendsTheDigestForADueVerifiedAccount(): void
    {
        $user = $this->verifiedUser();
        $duePreferences = $this->duePreferences($user);
        $search = (new SavedSearchMatchFixture($this->entityManager))
            ->oneMatch($user, 'rust', new \DateTimeImmutable('2026-08-28T08:30:00Z'));
        $savedSearches = $this->createStub(DigestSavedSearchesInterface::class);
        $savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $recipients = $this->createStub(DigestRecipientsInterface::class);
        $recipients->method('findWithDigestEnabled')->willReturn([$duePreferences]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects($this->once())->method('send')->with($user, self::isInstanceOf(DigestModel::class));

        $this->handler($recipients, $mailer, $savedSearches)->__invoke(new SendDueDigests());
    }

    public function testFiringLogsTheSweepReport(): void
    {
        $recipients = $this->createStub(DigestRecipientsInterface::class);
        $recipients->method('findWithDigestEnabled')->willReturn([]);
        $service = $this->service($recipients, $this->createStub(DigestMailerInterface::class));
        $logger = new RecordingLogger();

        (new SendDueDigestsHandler($service, $logger))->__invoke(new SendDueDigests());

        self::assertSame(
            [[
                'level' => 'info',
                'message' => 'Worker digest sweep finished.',
                'context' => ['report' => ['considered' => 0, 'sent' => 0, 'skippedEmpty' => 0]],
            ]],
            $logger->records,
        );
    }

    private function handler(
        DigestRecipientsInterface&Stub $recipients,
        DigestMailerInterface $mailer,
        ?DigestSavedSearchesInterface $savedSearches = null,
    ): SendDueDigestsHandler {
        return new SendDueDigestsHandler($this->service($recipients, $mailer, $savedSearches), new NullLogger());
    }

    private function service(
        DigestRecipientsInterface&Stub $recipients,
        DigestMailerInterface $mailer,
        ?DigestSavedSearchesInterface $savedSearches = null,
    ): SendDueDigestsService {
        return new SendDueDigestsService(
            $recipients,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $savedSearches ?? $this->createStub(DigestSavedSearchesInterface::class),
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
    }

    private function mailCapabilityEnabled(): MailCapability
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
    }

    /** Daily cadence, send hour 8, so at NOW (09:30) the occurrence is 08:00 today. */
    private function duePreferences(User $user): Preferences
    {
        $duePreferences = $user->getPreferences();
        $duePreferences->setDigestEnabled(true);
        $duePreferences->setDigestCadence(DigestCadence::Daily);
        $duePreferences->setDigestSendHour(8);
        $duePreferences->setDigestLastSentAt(null);

        return $duePreferences;
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

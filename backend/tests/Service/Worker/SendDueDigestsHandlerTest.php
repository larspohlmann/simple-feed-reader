<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

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
use App\Service\Mail\MailSendingSettings;
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
        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->handler($preferences, $mailer)->__invoke(new SendDueDigests());

        $this->addToAssertionCount(1);
    }

    public function testFiringSendsTheDigestForADueVerifiedAccount(): void
    {
        $user = $this->verifiedUser();
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

    public function testFiringLogsTheSweepReport(): void
    {
        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([]);
        $service = $this->service($preferences, $this->createStub(DigestMailerInterface::class));
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
        PreferencesRepository&Stub $preferences,
        DigestMailerInterface $mailer,
        ?SavedSearchRepository $savedSearches = null,
    ): SendDueDigestsHandler {
        return new SendDueDigestsHandler($this->service($preferences, $mailer, $savedSearches), new NullLogger());
    }

    private function service(
        PreferencesRepository&Stub $preferences,
        DigestMailerInterface $mailer,
        ?SavedSearchRepository $savedSearches = null,
    ): SendDueDigestsService {
        return new SendDueDigestsService(
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
    }

    private function mailCapabilityEnabled(): MailCapability
    {
        $settings = $this->createStub(MailSendingSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
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

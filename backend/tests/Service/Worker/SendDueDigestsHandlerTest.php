<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Tests\Support\FixedPublicBaseUrl;
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
use App\Service\Mail\Digest\SendDueDigests as SendDueDigestsService;
use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Worker\Handler\SendDueDigestsHandler;
use App\Service\Worker\Message\SendDueDigests;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
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
 */
final class SendDueDigestsHandlerTest extends TestCase
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
        $savedSearches = $this->createStub(SavedSearchRepository::class);
        $savedSearches->method('findIncludedInDigestForUser')->willReturn([
            new SavedSearch(new User('search-owner@example.com', new \DateTimeImmutable()), 'rust', false),
        ]);
        $entries = $this->createStub(EntryListRepository::class);
        $entries->method('unreadMatchIdsSince')->willReturn([1]);
        $entries->method('rowsByIdsForUser')->willReturn([$this->row(1)]);
        $preferences = $this->createStub(PreferencesRepository::class);
        $preferences->method('findWithDigestEnabled')->willReturn([$prefs]);
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::once())->method('send')->with($user, self::isInstanceOf(DigestModel::class));

        $this->handler($preferences, $mailer, $savedSearches, $entries)->__invoke(new SendDueDigests());
    }

    private function handler(
        PreferencesRepository&Stub $preferences,
        DigestMailerInterface $mailer,
        ?SavedSearchRepository $savedSearches = null,
        ?EntryListRepository $entries = null,
    ): SendDueDigestsHandler {
        $service = new SendDueDigestsService(
            $preferences,
            new DigestSchedule('UTC'),
            new DigestComposer(
                $savedSearches ?? $this->createStub(SavedSearchRepository::class),
                new DigestEntryFinder($entries ?? $this->createStub(EntryListRepository::class)),
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
            ),
            $mailer,
            $this->mailCapabilityEnabled(),
            new MockClock(self::NOW),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        return new SendDueDigestsHandler($service, new NullLogger());
    }

    private function mailCapabilityEnabled(): MailCapability
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        return new MailCapability($settings);
    }

    private function user(): User
    {
        $user = new User('digest@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        new \ReflectionProperty(User::class, 'id')->setValue($user, 1);
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

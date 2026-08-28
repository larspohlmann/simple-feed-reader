<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\SendTestDigest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The "send me a test digest" action (#636): it must compose over exactly the
 * requested window measured back from the clock, send only when there is
 * something to report, and never touch digestLastSentAt — that watermark is
 * DigestEnablement's and the real scheduled send's job, not a preview
 * button's.
 */
final class SendTestDigestTest extends TestCase
{
    private SavedSearchRepository&Stub $savedSearches;
    private EntryListRepository&Stub $entries;
    private DigestMailerInterface&Stub $mailer;
    private User $user;

    protected function setUp(): void
    {
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);
        $this->entries = $this->createStub(EntryListRepository::class);
        $this->mailer = $this->createStub(DigestMailerInterface::class);

        $this->user = new User('digest-test@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 7);
    }

    private function sendTestDigest(MockClock $clock, ?DigestMailerInterface $mailer = null): SendTestDigest
    {
        return new SendTestDigest(
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->entries),
                new DigestLinkBuilder('https://reader.example'),
            ),
            $mailer ?? $this->mailer,
            $clock,
        );
    }

    public function testNothingToReportSendsNoMailAndReturnsFalse(): void
    {
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([]);
        /** @var DigestMailerInterface&MockObject $mailer */
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $mailer)
            ->send($this->user, 7);

        self::assertFalse($result);
    }

    public function testAMatchIsSentAndReturnsTrue(): void
    {
        $search = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->entries->method('unreadMatchIdsSince')->willReturn([1]);
        $this->entries->method('rowsByIdsForUser')->willReturn([$this->row(1)]);

        /** @var DigestMailerInterface&MockObject $mailer */
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with($this->user, self::isInstanceOf(DigestModel::class));

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $mailer)
            ->send($this->user, 7);

        self::assertTrue($result);
    }

    /**
     * The window is measured from the clock, not from digestLastSentAt: a test
     * send previews "the last N days", independent of when the real schedule
     * last ran.
     */
    public function testTheSinceCutoffPassedToTheFinderIsDaysBeforeNow(): void
    {
        $search = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);

        $capturedSince = null;
        $captureSince = function (
            EntrySearchQuery $query,
            \DateTimeImmutable $since,
        ) use (&$capturedSince): array {
            $capturedSince = $since;

            return [];
        };
        $this->entries->method('unreadMatchIdsSince')->willReturnCallback($captureSince);

        $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'))->send($this->user, 3);

        self::assertEquals(new \DateTimeImmutable('2026-08-25T12:00:00Z'), $capturedSince);
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

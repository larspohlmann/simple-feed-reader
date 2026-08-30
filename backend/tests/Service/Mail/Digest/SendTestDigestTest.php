<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
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
use App\Service\Mail\Digest\DigestFormat;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailBuilder;
use App\Service\Mail\Digest\DigestMailer;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestPageBuilder;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\SendTestDigest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

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

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        $this->sentEmails = [];
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
                new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
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

    /**
     * A real DigestMailer/DigestMailBuilder chain (task 8's pattern), fed by a
     * stubbed transport, proves SendTestDigest routes through the format
     * branch end to end rather than through a mocked mailer.
     */
    private function realMailer(): DigestMailer
    {
        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email): void {
            $this->sentEmails[] = $email;
        });

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        $embedder = $this->createStub(DigestImageEmbedderInterface::class);
        $embedder->method('embed')->willReturn(new DigestImageSet([], []));

        $builder = new DigestMailBuilder(
            new DigestPageBuilder(),
            $embedder,
            new DigestTextRenderer($translator),
            new DigestHtmlRenderer($translator, $links),
            $links,
            'noreply@feeds.example.com',
            'Simple Feed Reader',
        );

        return new DigestMailer($transport, $builder);
    }

    private function stubAMatchToCompose(): void
    {
        $search = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);
        $this->entries->method('unreadMatchIdsSince')->willReturn([1]);
        $this->entries->method('rowsByIdsForUser')->willReturn([$this->row(1)]);
    }

    public function testHtmlFormatUserGetsAnHtmlBodyThroughTheRealMailer(): void
    {
        $this->stubAMatchToCompose();
        $this->user->getPreferences()->setDigestFormat(DigestFormat::Html);

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $this->realMailer())
            ->send($this->user, 7);

        self::assertTrue($result);
        self::assertCount(1, $this->sentEmails);
        self::assertNotNull($this->sentEmails[0]->getHtmlBody());
        self::assertNotNull($this->sentEmails[0]->getTextBody());
    }

    public function testTextFormatUserGetsNoHtmlBodyThroughTheRealMailer(): void
    {
        $this->stubAMatchToCompose();
        $this->user->getPreferences()->setDigestFormat(DigestFormat::Text);

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $this->realMailer())
            ->send($this->user, 7);

        self::assertTrue($result);
        self::assertCount(1, $this->sentEmails);
        self::assertNull($this->sentEmails[0]->getHtmlBody());
        self::assertNotNull($this->sentEmails[0]->getTextBody());
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

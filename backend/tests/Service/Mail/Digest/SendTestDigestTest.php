<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Enum\DigestFormat;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Mail\Digest\DigestBrandLogo;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedder\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailer\DigestMailer;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\Factory\DigestMailFactory;
use App\Service\Mail\Digest\Factory\DigestPageFactory;
use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Tests\DbTestCase;
use App\Tests\Support\DigestTwigEnvironment;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\SavedSearchMatchFixture;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
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
 *
 * DigestEntryFinder now reads the membership table through
 * SavedSearchEntryRepository, which is `final` and cannot be doubled, so a
 * match here is a real persisted saved-search member (#1116).
 */
final class SendTestDigestTest extends DbTestCase
{
    private DigestSavedSearchesInterface&Stub $savedSearches;
    private DigestMailerInterface&Stub $mailer;
    private User $user;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sentEmails = [];
        $this->savedSearches = $this->createStub(DigestSavedSearchesInterface::class);
        $this->mailer = $this->createStub(DigestMailerInterface::class);

        $this->user = new User('digest-test@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function sendTestDigest(MockClock $clock, ?DigestMailerInterface $mailer = null): SendTestDigest
    {
        return new SendTestDigest(
            new DigestComposer(
                $this->savedSearches,
                new DigestEntryFinder($this->members(), $this->entries()),
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
        $mailer->expects($this->never())->method('send');

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $mailer)
            ->send($this->user, 7);

        self::assertFalse($result);
    }

    public function testAMatchIsSentAndReturnsTrue(): void
    {
        $this->givenOneMatch(new \DateTimeImmutable('2026-08-27T00:00:00Z'));

        /** @var DigestMailerInterface&MockObject $mailer */
        $mailer = $this->createMock(DigestMailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->user, self::isInstanceOf(DigestModel::class));

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $mailer)
            ->send($this->user, 7);

        self::assertTrue($result);
    }

    /**
     * The window is measured from the clock, not from digestLastSentAt: a test
     * send previews "the last N days", independent of when the real schedule
     * last ran. An entry just inside that window is matched; one just outside
     * it is not.
     */
    public function testTheSinceCutoffPassedToTheFinderIsDaysBeforeNowAndIsExclusive(): void
    {
        $fixture = new SavedSearchMatchFixture($this->em);
        // now(2026-08-28T12:00:00Z) - 3 days = 2026-08-25T12:00:00Z, exclusive.
        $justInside = $fixture->oneMatch($this->user, 'rust-inside', new \DateTimeImmutable('2026-08-25T12:00:01Z'));
        $justOutside = $fixture->oneMatch($this->user, 'rust-outside', new \DateTimeImmutable('2026-08-25T11:59:59Z'));

        $active = $justInside;
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturnCallback(
            function () use (&$active): array {
                return [$active];
            },
        );

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'))->send($this->user, 3);
        self::assertTrue($result, 'An entry one second inside the window must be matched.');

        $active = $justOutside;
        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'))->send($this->user, 3);
        self::assertFalse($result, 'An entry one second outside the window must not be matched.');
    }

    /**
     * A real DigestMailer/DigestMailFactory chain (task 8's pattern), fed by a
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
        $embedder->method('embed')->willReturn(new DigestImageSetModel([], []));

        $builder = new DigestMailFactory(
            new DigestPageFactory(),
            $embedder,
            new DigestTextRenderer($translator),
            new DigestHtmlRenderer(
                DigestTwigEnvironment::withTranslator($translator),
                $links,
                new MockClock('2026-08-30T12:00:00Z'),
            ),
            $links,
            new DigestBrandLogo(\dirname(__DIR__, 4)),
            $this->mailIdentity('noreply@feeds.example.com', 'Simple Feed Reader'),
        );

        return new DigestMailer($transport, $builder);
    }

    public function testHtmlFormatUserGetsAnHtmlBodyThroughTheRealMailer(): void
    {
        $this->givenOneMatch(new \DateTimeImmutable('2026-08-27T00:00:00Z'));
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
        $this->givenOneMatch(new \DateTimeImmutable('2026-08-27T00:00:00Z'));
        $this->user->getPreferences()->setDigestFormat(DigestFormat::Text);

        $result = $this->sendTestDigest(new MockClock('2026-08-28T12:00:00Z'), $this->realMailer())
            ->send($this->user, 7);

        self::assertTrue($result);
        self::assertCount(1, $this->sentEmails);
        self::assertNull($this->sentEmails[0]->getHtmlBody());
        self::assertNotNull($this->sentEmails[0]->getTextBody());
    }

    private function mailIdentity(string $address, string $name): MailSendingSettingsInterface
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('identity')->willReturn(new MailIdentityModel($address, $name));

        return $settings;
    }

    private function givenOneMatch(\DateTimeImmutable $effectiveDate): SavedSearch
    {
        $term = 'rust-' . uniqid('', true);
        $search = (new SavedSearchMatchFixture($this->em))->oneMatch($this->user, $term, $effectiveDate);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$search]);

        return $search;
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

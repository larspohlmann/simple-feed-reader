<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest\DigestMailer;

use App\Entity\User;
use App\Enum\DigestFormat;
use App\Service\Mail\Digest\DigestBrandLogo;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageEmbedder\DigestImageEmbedderInterface;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestMailer\DigestMailer;
use App\Service\Mail\Digest\DigestTextRenderer;
use App\Service\Mail\Digest\Factory\DigestMailFactory;
use App\Service\Mail\Digest\Factory\DigestPageFactory;
use App\Service\Mail\Digest\Model\DigestEntryModel;
use App\Service\Mail\Digest\Model\DigestGroupModel;
use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Tests\Support\DigestTwigEnvironment;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** Wraps a real DigestMailFactory, which picks the message shape, and asserts what reaches the stubbed mailer. */
final class DigestMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];
    private DigestMailer $mailer;

    protected function setUp(): void
    {
        $this->sent = [];

        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email): void {
            $this->sent[] = $email;
        });

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translationsDirectory = \dirname(__DIR__, 5) . '/translations';
        $translator->addResource('yaml', "{$translationsDirectory}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$translationsDirectory}/emails.de.yaml", 'de', 'emails');
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
            new DigestBrandLogo(\dirname(__DIR__, 5)),
            $this->mailIdentity('noreply@feeds.example.com', 'Simple Feed Reader'),
        );

        $this->mailer = new DigestMailer($transport, $builder);
    }

    private function model(): DigestModel
    {
        $entries = [
            new DigestEntryModel(
                'Rust 1.80 released',
                'Rust Blog',
                'A short summary.',
                'https://example.com/1',
                null,
                null,
                null,
            ),
        ];
        $group = new DigestGroupModel('rust', 1, $entries, false, '');

        return new DigestModel([$group], 1);
    }

    private function user(DigestFormat $format): User
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->getPreferences()->setDigestFormat($format);

        return $user;
    }

    public function testSendHandsTheMailerAnEmailWithFromToSubjectAndBody(): void
    {
        $this->mailer->send($this->user(DigestFormat::Html), $this->model());

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];

        self::assertSame('noreply@feeds.example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('Simple Feed Reader', $email->getFrom()[0]->getName());
        self::assertSame('reader@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('1', (string) $email->getSubject());

        $body = (string) $email->getTextBody();
        self::assertStringContainsString('Rust 1.80 released', $body);
        self::assertStringContainsString('https://example.com/1', $body);
    }

    public function testSendRendersInTheUsersLocale(): void
    {
        $user = $this->user(DigestFormat::Html);
        $user->setLocale('de');

        $this->mailer->send($user, $this->model());

        self::assertStringContainsString('Einstellungen', (string) $this->sent[0]->getTextBody());
    }

    public function testSendDefaultsToEnglishWhenNoLocaleIsSet(): void
    {
        $this->mailer->send($this->user(DigestFormat::Html), $this->model());

        self::assertStringContainsString('Settings', (string) $this->sent[0]->getTextBody());
    }

    public function testHtmlFormatUserGetsBothHtmlAndTextParts(): void
    {
        $this->mailer->send($this->user(DigestFormat::Html), $this->model());

        $email = $this->sent[0];
        self::assertNotNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertStringContainsString('Settings', (string) $email->getTextBody());
    }

    public function testTextFormatUserGetsNoHtmlPart(): void
    {
        $this->mailer->send($this->user(DigestFormat::Text), $this->model());

        $email = $this->sent[0];
        self::assertNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
    }

    private function mailIdentity(string $address, string $name): MailSendingSettingsInterface
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('identity')->willReturn(new MailIdentityModel($address, $name));

        return $settings;
    }
}

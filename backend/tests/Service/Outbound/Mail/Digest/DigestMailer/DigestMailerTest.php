<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail\Digest\DigestMailer;

use App\Entity\DigestSettings;
use App\Enum\DigestFormat;
use App\Service\Outbound\Mail\Digest\DigestBrandLogo;
use App\Service\Outbound\Mail\Digest\DigestHtmlRenderer;
use App\Service\Outbound\Mail\Digest\DigestImageEmbedder\DigestImageEmbedderInterface;
use App\Service\Outbound\Mail\Digest\DigestLinkBuilder;
use App\Service\Outbound\Mail\Digest\DigestMailer\DigestMailer;
use App\Service\Outbound\Mail\Digest\DigestTextRenderer;
use App\Service\Outbound\Mail\Digest\Factory\DigestMailFactory;
use App\Service\Outbound\Mail\Digest\Factory\DigestPageFactory;
use App\Service\Outbound\Mail\Digest\Model\DigestEntryModel;
use App\Service\Outbound\Mail\Digest\Model\DigestGroupModel;
use App\Service\Outbound\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Outbound\Mail\Digest\Model\DigestModel;
use App\Service\Outbound\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Outbound\Mail\Settings\Model\MailIdentityModel;
use App\Tests\Support\DigestTwigEnvironment;
use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\UnsavedUserSettings;
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
        $translationsDirectory = \dirname(__DIR__, 6) . '/translations';
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
            new DigestBrandLogo(\dirname(__DIR__, 6)),
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

    private function settings(DigestFormat $format): DigestSettings
    {
        $settings = UnsavedUserSettings::digest();
        $settings->setFormat($format);

        return $settings;
    }

    public function testSendHandsTheMailerAnEmailWithFromToSubjectAndBody(): void
    {
        $this->mailer->send($this->settings(DigestFormat::Html), $this->model());

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
        $settings = $this->settings(DigestFormat::Html);
        $settings->getUser()->setLocale('de');

        $this->mailer->send($settings, $this->model());

        self::assertStringContainsString('Einstellungen', (string) $this->sent[0]->getTextBody());
    }

    public function testSendDefaultsToEnglishWhenNoLocaleIsSet(): void
    {
        $this->mailer->send($this->settings(DigestFormat::Html), $this->model());

        self::assertStringContainsString('Settings', (string) $this->sent[0]->getTextBody());
    }

    public function testHtmlFormatUserGetsBothHtmlAndTextParts(): void
    {
        $this->mailer->send($this->settings(DigestFormat::Html), $this->model());

        $email = $this->sent[0];
        self::assertNotNull($email->getHtmlBody());
        self::assertNotNull($email->getTextBody());
        self::assertStringContainsString('Settings', (string) $email->getTextBody());
    }

    public function testTextFormatUserGetsNoHtmlPart(): void
    {
        $this->mailer->send($this->settings(DigestFormat::Text), $this->model());

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

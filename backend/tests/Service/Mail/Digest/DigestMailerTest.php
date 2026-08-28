<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestMailer;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestTextRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * DigestMailer renders (per User::$locale) and hands the MailerInterface a
 * plain-text Email (#636). The renderer is the real DigestTextRenderer
 * against the shipped `emails` translation files, so this test exercises
 * the actual localised subject/body rather than a fixture that could drift.
 */
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
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');

        $this->mailer = new DigestMailer(
            $transport,
            new DigestTextRenderer($translator),
            'noreply@feeds.example.com',
            'Simple Feed Reader',
        );
    }

    private function model(): DigestModel
    {
        $entries = [
            new DigestEntry('Rust 1.80 released', 'Rust Blog', 'A short summary.', 'https://example.com/1'),
        ];
        $group = new DigestGroup('rust', 1, $entries, false, '');

        return new DigestModel([$group], 1);
    }

    public function testSendHandsTheMailerAPlainTextEmailWithFromToSubjectAndBody(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));

        $this->mailer->send($user, $this->model());

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];

        self::assertSame('noreply@feeds.example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('Simple Feed Reader', $email->getFrom()[0]->getName());
        self::assertSame('reader@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('1', (string) $email->getSubject());
        self::assertNull($email->getHtmlBody());

        $body = (string) $email->getTextBody();
        self::assertStringContainsString('Rust 1.80 released', $body);
        self::assertStringContainsString('https://example.com/1', $body);
    }

    public function testSendRendersInTheUsersLocale(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->setLocale('de');

        $this->mailer->send($user, $this->model());

        self::assertStringContainsString('Einstellungen', (string) $this->sent[0]->getTextBody());
    }

    public function testSendDefaultsToEnglishWhenNoLocaleIsSet(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));

        $this->mailer->send($user, $this->model());

        self::assertStringContainsString('Settings', (string) $this->sent[0]->getTextBody());
    }
}

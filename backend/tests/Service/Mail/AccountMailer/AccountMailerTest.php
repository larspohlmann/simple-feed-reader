<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\AccountMailer;

use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Service\Mail\AccountMailer\AccountMailer;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Model\PendingApprovalNoticeModel;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Service\Settings\PublicBaseUrl\PublicBaseUrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class AccountMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];
    private AccountMailer $mailer;

    protected function setUp(): void
    {
        $this->sent = [];

        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email): void {
            $this->sent[] = $email;
        });

        // The real shipped translation files, so the test exercises the actual
        // localised bodies rather than a fixture that could drift from them.
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translationsDirectory = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$translationsDirectory}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$translationsDirectory}/emails.de.yaml", 'de', 'emails');

        $publicBaseUrl = new class implements PublicBaseUrlInterface {
            public function get(): string
            {
                return 'https://feeds.example.com';
            }
        };

        $mailSettings = $this->createStub(MailSendingSettingsInterface::class);
        $mailSettings->method('identity')->willReturn(
            new MailIdentityModel('noreply@feeds.example.com', 'Simple Feed Reader'),
        );

        $this->mailer = new AccountMailer(
            $transport,
            $translator,
            $mailSettings,
            $publicBaseUrl,
        );
    }

    private function user(string $locale = 'en'): User
    {
        $user = new User('new@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $user->setLocale($locale);

        return $user;
    }

    public function testVerificationMailCarriesTheLink(): void
    {
        $this->mailer->sendVerification($this->user(), 'plain-token-value');

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];

        self::assertSame('new@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('noreply@feeds.example.com', $email->getFrom()[0]->getAddress());
        self::assertStringContainsString(
            'https://feeds.example.com/verify-email?token=plain-token-value',
            (string) $email->getTextBody(),
        );
    }

    public function testPasswordResetMailCarriesTheLink(): void
    {
        $this->mailer->sendPasswordReset($this->user(), 'reset-token-value');

        self::assertCount(1, $this->sent);
        self::assertStringContainsString(
            'https://feeds.example.com/reset-password?token=reset-token-value',
            (string) $this->sent[0]->getTextBody(),
        );
    }

    public function testApprovalMailHasNoToken(): void
    {
        $this->mailer->sendApproved($this->user());

        self::assertCount(1, $this->sent);
        $body = (string) $this->sent[0]->getTextBody();

        self::assertStringContainsString('https://feeds.example.com', $body);
        self::assertStringNotContainsString('token=', $body);
    }

    public function testMailsAreLocalisedToTheRecipientLanguage(): void
    {
        $this->mailer->sendVerification($this->user('de'), 'tok');

        $email = $this->sent[0];
        self::assertSame('Bestätige deine E-Mail-Adresse', $email->getSubject());
        self::assertStringContainsString('Willkommen bei Simple Feed Reader.', (string) $email->getTextBody());
        // The link still lands on the same frontend route regardless of language.
        self::assertStringContainsString(
            'https://feeds.example.com/verify-email?token=tok',
            (string) $email->getTextBody(),
        );
    }

    public function testEnglishIsTheDefaultLanguage(): void
    {
        $this->mailer->sendVerification($this->user(), 'tok');

        self::assertSame('Confirm your email address', $this->sent[0]->getSubject());
    }

    private function admin(string $locale = 'en'): User
    {
        $admin = new User('admin@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
        $admin->setLocale($locale);

        return $admin;
    }

    public function testPendingApprovalNoticeCarriesApplicantMethodCountAndLink(): void
    {
        $notice = new PendingApprovalNoticeModel(
            'newcomer@example.com',
            RegistrationMethod::EmailPassword,
            null,
            'https://feeds.example.com/admin/users',
            3,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin(), $notice);

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('A new user is awaiting approval', $email->getSubject());

        $body = (string) $email->getTextBody();
        self::assertStringContainsString('newcomer@example.com', $body);
        self::assertStringContainsString('email and password', $body);
        self::assertStringContainsString('Users awaiting approval: 3', $body);
        self::assertStringContainsString('https://feeds.example.com/admin/users', $body);
    }

    public function testPendingApprovalNoticeNamesTheOAuthProvider(): void
    {
        $notice = new PendingApprovalNoticeModel(
            'newcomer@example.com',
            RegistrationMethod::OAuth,
            'google',
            'https://feeds.example.com/admin/users',
            1,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin(), $notice);

        self::assertStringContainsString('Signed up via: Google', (string) $this->sent[0]->getTextBody());
    }

    public function testPendingApprovalNoticeIsLocalisedToTheAdmin(): void
    {
        $notice = new PendingApprovalNoticeModel(
            'newcomer@example.com',
            RegistrationMethod::EmailPassword,
            null,
            'https://feeds.example.com/admin/users',
            1,
        );

        $this->mailer->sendPendingApprovalNotice($this->admin('de'), $notice);

        $email = $this->sent[0];
        self::assertSame('Ein neuer Nutzer wartet auf Freischaltung', $email->getSubject());
        self::assertStringContainsString('E-Mail und Passwort', (string) $email->getTextBody());
    }

    public function testTokensAreUrlEncodedInLinks(): void
    {
        $this->mailer->sendVerification($this->user(), 'a+b/c=d');

        self::assertStringContainsString('token=a%2Bb%2Fc%3Dd', (string) $this->sent[0]->getTextBody());
    }

    /** rawurlencode (RFC 3986), not urlencode: a space becomes %20 and `+` becomes %2B, never a `+` read as a space. */
    public function testTokenEncodingUsesRfc3986NotFormEncoding(): void
    {
        $this->mailer->sendVerification($this->user(), 'a b+c');

        $body = (string) $this->sent[0]->getTextBody();

        self::assertStringContainsString('token=a%20b%2Bc', $body);
        self::assertStringNotContainsString('token=a+b', $body);
    }

    /**
     * The bodies are written as indented heredocs. PHP 7.3+ strips the closing
     * marker's indentation from every line, but that is easy to break silently
     * on a later edit, so assert the rendered result rather than trusting it.
     *
     * @return iterable<string, array{\Closure(AccountMailer): void}>
     */
    public static function bodyProvider(): iterable
    {
        $user = new User('new@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));

        yield 'verification' => [static fn (AccountMailer $mailer) => $mailer->sendVerification($user, 'tok')];
        yield 'approved' => [static fn (AccountMailer $mailer) => $mailer->sendApproved($user)];
        yield 'password reset' => [static fn (AccountMailer $mailer) => $mailer->sendPasswordReset($user, 'tok')];
        yield 'admin pending approval' => [static function (AccountMailer $mailer) use ($user): void {
            $mailer->sendPendingApprovalNotice($user, new PendingApprovalNoticeModel(
                'newcomer@example.com',
                RegistrationMethod::EmailPassword,
                null,
                'https://feeds.example.com/admin/users',
                1,
            ));
        }];
    }

    /** @param \Closure(AccountMailer): void $send */
    #[DataProvider('bodyProvider')]
    public function testBodiesHaveNoLeadingIndentation(\Closure $send): void
    {
        $send($this->mailer);

        $body = (string) $this->sent[0]->getTextBody();

        foreach (explode("\n", $body) as $line) {
            self::assertSame(ltrim($line), $line, sprintf('Line is indented: %s', var_export($line, true)));
        }
    }

    public function testTheLinkLineIsFlushLeft(): void
    {
        $this->mailer->sendVerification($this->user(), 'plain-token-value');

        self::assertStringContainsString(
            "\nhttps://feeds.example.com/verify-email?token=plain-token-value\n",
            (string) $this->sent[0]->getTextBody(),
        );
    }

    /**
     * Header-injection probe: registration validation is the real defence, but the mime layer must refuse control
     * characters on its own, so a gap upstream cannot become a smuggled Bcc.
     */
    public function testAnEmailContainingCrlfIsRejectedByTheMimeLayer(): void
    {
        $user = new User("a@b.com\nBcc: victim@example.com", new \DateTimeImmutable('2026-07-21 12:00:00'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');

        $this->mailer->sendVerification($user, 'tok');
    }
}

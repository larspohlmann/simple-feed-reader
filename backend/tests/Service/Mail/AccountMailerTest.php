<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User;
use App\Service\Mail\AccountMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class AccountMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];
    private AccountMailer $mailer;

    protected function setUp(): void
    {
        $this->sent = [];

        // A stub rather than a mock: the assertions are all made against the
        // captured Email objects, so configuring call expectations here would
        // only duplicate them (and PHPUnit 12 emits a notice for mocks that
        // never have any).
        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email): void {
            $this->sent[] = $email;
        });

        $this->mailer = new AccountMailer(
            $transport,
            'noreply@feeds.example.com',
            'Simple Feed Reader',
            'https://feeds.example.com',
        );
    }

    private function user(): User
    {
        return new User('new@example.com', new \DateTimeImmutable('2026-07-21 12:00:00'));
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

    public function testTokensAreUrlEncodedInLinks(): void
    {
        $this->mailer->sendVerification($this->user(), 'a+b/c=d');

        self::assertStringContainsString('token=a%2Bb%2Fc%3Dd', (string) $this->sent[0]->getTextBody());
    }

    /**
     * `rawurlencode` is RFC 3986 percent-encoding, which is the correct choice
     * for a query *value*: unlike `urlencode` it leaves a space as %20 rather
     * than `+`, and `+` itself becomes %2B instead of surviving literally to be
     * misread as a space by the recipient.
     */
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

        yield 'verification' => [static fn (AccountMailer $m) => $m->sendVerification($user, 'tok')];
        yield 'approved' => [static fn (AccountMailer $m) => $m->sendApproved($user)];
        yield 'password reset' => [static fn (AccountMailer $m) => $m->sendPasswordReset($user, 'tok')];
    }

    /** @param \Closure(AccountMailer): void $send */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodyProvider')]
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
     * Header-injection probe. Registration input validation (Task 12) is the
     * real defence, but confirm the mime layer refuses control characters on
     * its own so a gap upstream cannot turn into a smuggled Bcc.
     */
    public function testAnEmailContainingCrlfIsRejectedByTheMimeLayer(): void
    {
        $user = new User("a@b.com\nBcc: victim@example.com", new \DateTimeImmutable('2026-07-21 12:00:00'));

        $this->expectException(\Symfony\Component\Mime\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');

        $this->mailer->sendVerification($user, 'tok');
    }
}

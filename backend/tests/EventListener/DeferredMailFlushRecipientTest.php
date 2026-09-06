<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\MailKind;
use App\EventListener\DeferredMailFlushListener;
use App\Service\Mail\DeferredMailer;
use App\Tests\Support\InMemoryMailFailureRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * AccountMailer::send() calls $this->mailer->send($email) with no explicit
 * Envelope, so every real account mail queues with a null envelope. The
 * recipient must still come from the message itself (#882).
 */
final class DeferredMailFlushRecipientTest extends TestCase
{
    private function throwingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('relay refused');
            }
        };
    }

    public function testAFailedSendWithNoEnvelopeRecordsTheMessageRecipient(): void
    {
        $health = new InMemoryMailFailureRecorder();
        $deferred = new DeferredMailer($this->throwingMailer());
        $deferred->send((new Email())
            ->from('noreply@example.test')
            ->to('new@example.test')
            ->subject('Verify your email')
            ->text('link'));

        (new DeferredMailFlushListener($deferred, new NullLogger(), $health))->onKernelTerminate();

        $failures = $health->recordedFailures();
        self::assertCount(1, $failures);
        self::assertSame(MailKind::Account, $failures[0]['kind']);
        self::assertSame('new@example.test', $failures[0]['recipient']);
    }

    public function testAFailedSendWithNoRecipientAtAllFallsBackToUnknown(): void
    {
        $health = new InMemoryMailFailureRecorder();
        $deferred = new DeferredMailer($this->throwingMailer());
        $deferred->send((new Email())
            ->from('noreply@example.test')
            ->subject('x')
            ->text('y'));

        (new DeferredMailFlushListener($deferred, new NullLogger(), $health))->onKernelTerminate();

        $failures = $health->recordedFailures();
        self::assertCount(1, $failures);
        self::assertSame('unknown', $failures[0]['recipient']);
    }
}

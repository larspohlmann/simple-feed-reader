<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\MailKind;
use App\EventListener\DeferredMailFlushListener;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\DeferredMailer;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;
use App\Tests\Support\InMemoryMailFailureRecorder;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class DeferredMailFlushHealthTest extends DbTestCase
{
    public function testAFailedDeferredSendRecordsAnAccountFailure(): void
    {
        $health = new InMemoryMailFailureRecorder();

        $exploding = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('relay refused');
            }
        };
        $deferred = new DeferredMailer($exploding);
        $deferred->send(new Email()->to('new@example.test'), new Envelope(
            new Address('noreply@example.test'),
            [new Address('new@example.test')],
        ));

        (new DeferredMailFlushListener($deferred, new NullLogger(), $health))->onKernelTerminate();

        $failures = $health->recordedFailures();
        self::assertCount(1, $failures);
        self::assertSame(MailKind::Account, $failures[0]['kind']);
        self::assertSame('new@example.test', $failures[0]['recipient']);
    }

    public function testASuccessfulDeferredFlushViaTheRealDispatcherClearsFailures(): void
    {
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $health->recordFailure(MailKind::Digest, 'old@example.test', 'earlier outage');

        /** @var DeferredMailer $deferred */
        $deferred = self::getContainer()->get(DeferredMailer::class);
        $deferred->send(new Email()
            ->from('noreply@example.test')
            ->to('new@example.test')
            ->subject('Verify your email')
            ->text('link'));

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        /** @var HttpKernelInterface $kernel */
        $kernel = self::getContainer()->get(HttpKernelInterface::class);
        $dispatcher->dispatch(
            new TerminateEvent($kernel, Request::create('/'), new Response()),
            'kernel.terminate',
        );

        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        self::assertSame(0, $failures->countAll());
    }
}

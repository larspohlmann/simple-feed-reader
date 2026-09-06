<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\MailKind;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\DeferredMailer;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mime\Email;

final class DeferredMailFlushHealthTest extends DbTestCase
{
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

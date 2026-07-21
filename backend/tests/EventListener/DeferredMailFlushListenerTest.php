<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\DeferredMailFlushListener;
use App\Kernel;
use App\Service\Auth\AltchaService;
use App\Service\Mail\DeferredMailer;
use App\Tests\Support\AltchaSolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The deferral is only safe if terminate genuinely runs. A listener that
 * silently never fired would be worse than the timing leak it closes, because
 * it would fail closed on *delivery* — users would simply never get their
 * verification link, and nothing would say so.
 *
 * So this asserts the ordering directly against the kernel rather than trusting
 * the framework: after handle() the mail must still be queued and unsent, and
 * only terminate() may release it.
 */
final class DeferredMailFlushListenerTest extends KernelTestCase
{
    private function registerRequest(): Request
    {
        /** @var AltchaService $altcha */
        $altcha = self::getContainer()->get(AltchaService::class);

        return Request::create(
            '/api/auth/register',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'email' => 'deferred@example.com',
                'password' => 'correct-horse-battery',
                'altcha' => AltchaSolver::solve($altcha),
            ]),
        );
    }

    public function testMailIsStillQueuedWhenTheResponseIsReadyAndOnlySentOnTerminate(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);

        /** @var CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        $rateLimiterCache->clear();

        /** @var DeferredMailer $deferred */
        $deferred = self::getContainer()->get(DeferredMailer::class);

        $request = $this->registerRequest();
        $response = $kernel->handle($request);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());

        // The load-bearing assertion: the response the user is waiting on is
        // fully formed while the SMTP round trip has not started. Everything
        // the timing fix depends on is this ordering.
        self::assertTrue(
            $deferred->hasQueuedMail(),
            'the verification mail must still be queued when the response is ready',
        );

        $kernel->terminate($request, $response);

        self::assertFalse($deferred->hasQueuedMail(), 'terminate must drain the queue');
        self::assertEmailCount(1, message: 'the mail must actually be sent, not merely dequeued');
    }

    /**
     * A transport failure must not escape into terminate, where it would land
     * after the response as an unattributable fatal. It is logged, and the
     * queue still drains so one bad message cannot block the rest.
     */
    public function testATransportFailureIsSwallowedAndTheQueueStillDrains(): void
    {
        $exploding = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('SMTP is down');
            }
        };

        $mailer = new DeferredMailer($exploding);
        $mailer->send(new RawMessage('first'));
        $mailer->send(new RawMessage('second'));

        $listener = new DeferredMailFlushListener($mailer, new NullLogger());
        $listener->onKernelTerminate();

        self::assertFalse($mailer->hasQueuedMail());
    }
}

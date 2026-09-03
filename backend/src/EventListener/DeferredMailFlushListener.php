<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Mail\DeferredMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Flushes DeferredMailer once the work the user is waiting on is finished.
 *
 * kernel.terminate is the HTTP hook: the Runtime component sends the response,
 * calls fastcgi_finish_request() (or closes/flushes output buffers), and only
 * then calls Kernel::terminate — so the client already has its bytes and the
 * SMTP round trip is outside anything it can time. terminate is unconditional,
 * so deferring cannot silently drop a verification mail.
 *
 * console.terminate covers the other half: kernel.terminate never fires for CLI,
 * so a command sending account mail (admin approval, a maintenance script) would
 * otherwise queue into a DeferredMailer nothing drains.
 */
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
#[AsEventListener(event: ConsoleTerminateEvent::class, method: 'onConsoleTerminate')]
final readonly class DeferredMailFlushListener
{
    public function __construct(
        private DeferredMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function onKernelTerminate(): void
    {
        $this->flush();
    }

    public function onConsoleTerminate(): void
    {
        $this->flush();
    }

    /**
     * Failures are logged, never rethrown. The response has already gone out,
     * so there is nobody left to tell: an exception escaping terminate would
     * only turn a lost email into a lost email plus a fatal in the log with no
     * indication of which message it was. One message failing must also not
     * stop the rest of the queue.
     */
    private function flush(): void
    {
        foreach ($this->mailer->take() as [$message, $envelope]) {
            try {
                $this->mailer->sendNow($message, $envelope);
            } catch (\Throwable $exception) {
                $this->logger->error('Deferred mail delivery failed', [
                    'exception' => $exception,
                ]);
            }
        }
    }
}

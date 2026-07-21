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
 * kernel.terminate is the HTTP hook. The Runtime component sends the response,
 * calls fastcgi_finish_request() (or falls back to closing output buffers and
 * flushing), and only then calls Kernel::terminate — so by the time this runs
 * the client already has its bytes and the SMTP round trip is outside anything
 * it can time. Crucially, terminate is unconditional: there is no branch in
 * which the response is sent and terminate is skipped, so deferring cannot
 * silently drop a verification mail.
 *
 * console.terminate is the other half, and it is not hypothetical: kernel
 * terminate never fires for CLI, so any command that ends up sending account
 * mail — an admin approval command, a maintenance script — would otherwise
 * queue it into a DeferredMailer that nothing ever drains. Covering both exits
 * means the queue is drained on every path that has one.
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

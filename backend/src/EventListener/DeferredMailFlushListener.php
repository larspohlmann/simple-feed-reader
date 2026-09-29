<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\MailKind;
use App\Service\Mail\DeferredMailer;
use App\Service\Mail\MailFailureRecorder\MailFailureRecorderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends DeferredMailer's queue once the response is on the wire (kernel.terminate), and after console commands, which
 * never reach kernel.terminate.
 */
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
#[AsEventListener(event: ConsoleTerminateEvent::class, method: 'onConsoleTerminate')]
final readonly class DeferredMailFlushListener
{
    public function __construct(
        private DeferredMailer $mailer,
        private LoggerInterface $logger,
        private MailFailureRecorderInterface $health,
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

    /** Logs a failure and never rethrows: the response is gone, and one bad message must not stop the rest. */
    private function flush(): void
    {
        foreach ($this->mailer->take() as [$message, $envelope]) {
            try {
                $this->mailer->sendNow($message, $envelope);
            } catch (\Throwable $exception) {
                $this->logger->error('Deferred mail delivery failed', [
                    'exception' => $exception,
                ]);
                $this->health->recordFailure(
                    MailKind::Account,
                    $this->recipientOf($message, $envelope),
                    $exception->getMessage(),
                );

                continue;
            }

            $this->health->recordSuccess();
        }
    }

    private function recipientOf(RawMessage $message, ?Envelope $envelope): string
    {
        $addresses = null !== $envelope
            ? $envelope->getRecipients()
            : ($message instanceof Email ? $message->getTo() : []);

        if ([] === $addresses) {
            return 'unknown';
        }

        return implode(', ', array_map(
            static fn (Address $address): string => $address->getAddress(),
            $addresses,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\DependencyInjection\ProcessLifetimeState;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;
use App\Service\Mail\Transport\Factory\ActiveMailTransportFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The one mailer transport. It resolves the active transport at SEND time, never
 * at construction: the DB is not reachable during cache:warmup. A saved SMTP row
 * wins; otherwise the env fallback DSN is used. The built transport is memoised
 * per signature so a digest batch does not reconnect per message. The fallback is
 * built with the app's dispatcher/logger/client so the message-logger listener
 * still collects sent messages, and from the DEFAULT factory set — which does not
 * include `dynamic` — so there is no recursion.
 */
#[ProcessLifetimeState('The transport is cached per settings signature, re-checked on every send')]
final class DynamicMailTransport implements TransportInterface
{
    private ?TransportInterface $cached = null;
    private ?string $cachedSignature = null;

    public function __construct(
        private readonly MailSendingSettingsInterface $settings,
        private readonly ActiveMailTransportFactory $transportFactory,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->activeTransport()->send($message, $envelope);
    }

    public function activeTransport(): TransportInterface
    {
        $resolved = $this->configuredTransport();
        try {
            return $this->transportFor($resolved);
        } catch (IncompleteMailConfigurationException $e) {
            // A row that routes through the egress proxy after that proxy's config
            // was removed. Surfaced as a transport failure so the send path degrades
            // the way a dead relay already does, not as an HTTP 422 in a worker.
            throw new TransportException('The mail configuration is incomplete: ' . $e->getMessage(), previous: $e);
        } catch (SecretUnreadableException $e) {
            throw new TransportException('The stored proxy password is unreadable: ' . $e->getMessage(), previous: $e);
        }
    }

    private function configuredTransport(): ?ResolvedMailTransportModel
    {
        try {
            return $this->settings->configuredTransport();
        } catch (SecretUnreadableException $e) {
            // A rotated INSTANCE_SECRET_KEY. Surfaced as a transport failure so
            // every send path degrades the way a dead relay already does.
            throw new TransportException('The stored mail password is unreadable: ' . $e->getMessage(), 0, $e);
        }
    }

    private function transportFor(?ResolvedMailTransportModel $resolved): TransportInterface
    {
        $signature = null !== $resolved
            ? 'db:' . $this->transportFactory->signatureOf($resolved)
            : 'fallback:' . $this->settings->activeTransportDsnFallback();

        if ($signature === $this->cachedSignature && null !== $this->cached) {
            return $this->cached;
        }

        $this->cached = null !== $resolved
            ? $this->transportFactory->forResolved($resolved, $this->dispatcher, $this->logger)
            : $this->buildFallback();
        $this->cachedSignature = $signature;

        return $this->cached;
    }

    private function buildFallback(): TransportInterface
    {
        return $this->transportFactory->forFallbackDsn(
            $this->settings->activeTransportDsnFallback(),
            $this->dispatcher,
            $this->logger,
        );
    }

    public function __toString(): string
    {
        return 'dynamic://default';
    }
}

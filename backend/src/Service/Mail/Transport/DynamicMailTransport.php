<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\Settings\MailSettings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one mailer transport. It resolves the active transport at SEND time, never
 * at construction: the DB is not reachable during cache:warmup. A saved SMTP row
 * wins; otherwise the env fallback DSN is used. The built transport is memoised
 * per signature so a digest batch does not reconnect per message. The fallback is
 * built with the app's dispatcher/logger/client so the message-logger listener
 * still collects sent messages, and from the DEFAULT factory set — which does not
 * include `dynamic` — so there is no recursion.
 */
final class DynamicMailTransport implements TransportInterface
{
    private ?TransportInterface $cached = null;
    private ?string $cachedSignature = null;

    public function __construct(
        private readonly MailSettings $settings,
        private readonly ActiveMailTransportFactory $transportFactory,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->activeTransport()->send($message, $envelope);
    }

    public function activeTransport(): TransportInterface
    {
        try {
            $resolved = $this->settings->configuredTransport();
        } catch (SecretUnreadableException $e) {
            // A rotated INSTANCE_SECRET_KEY. Surfaced as a transport failure so
            // every send path degrades the way a dead relay already does.
            throw new TransportException('The stored mail password is unreadable: ' . $e->getMessage(), 0, $e);
        }
        $signature = null !== $resolved
            ? 'db:' . $resolved->signature()
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
        return Transport::fromDsn(
            $this->settings->activeTransportDsnFallback(),
            $this->dispatcher,
            $this->httpClient,
            $this->logger,
        );
    }

    public function __toString(): string
    {
        return 'dynamic://default';
    }
}

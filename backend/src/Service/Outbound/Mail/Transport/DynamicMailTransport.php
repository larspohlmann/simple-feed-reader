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
 * Resolves the transport at send time, never at construction: the DB is unreachable during cache:warmup. The fallback
 * gets the app's dispatcher, so the message-logger listener still sees its sends, and comes from the DEFAULT factory
 * set, which has no `dynamic` scheme, so it cannot recurse into this class.
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
        } catch (IncompleteMailConfigurationException $exception) {
            // A row that routes through the egress proxy after that proxy's config
            // was removed. Surfaced as a transport failure so the send path degrades
            // the way a dead relay already does, not as an HTTP 422 in a worker.
            throw new TransportException(
                'The mail configuration is incomplete: ' . $exception->getMessage(),
                previous: $exception,
            );
        } catch (SecretUnreadableException $exception) {
            throw new TransportException(
                'The stored proxy password is unreadable: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function configuredTransport(): ?ResolvedMailTransportModel
    {
        try {
            return $this->settings->configuredTransport();
        } catch (SecretUnreadableException $exception) {
            // A rotated INSTANCE_SECRET_KEY. Surfaced as a transport failure so
            // every send path degrades the way a dead relay already does.
            throw new TransportException(
                'The stored mail password is unreadable: ' . $exception->getMessage(),
                previous: $exception,
            );
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

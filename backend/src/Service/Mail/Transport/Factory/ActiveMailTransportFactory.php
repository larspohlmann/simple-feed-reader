<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport\Factory;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;
use App\Service\Mail\Transport\CurlSmtpTransport;
use App\Service\Proxy\ConfiguredProxySource\ConfiguredProxySourceInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the active mail transport: a resolved row becomes an EsmtpTransport, or the
 * curl proxy transport when it routes through the configured proxy; no row falls back
 * to the env DSN.
 */
final readonly class ActiveMailTransportFactory
{
    public function __construct(
        private ConfiguredProxySourceInterface $proxySource,
        private HttpClientInterface $httpClient,
        private EsmtpTransportFactory $esmtpTransports,
    ) {
    }

    public function forResolved(
        ResolvedMailTransportModel $resolved,
        ?EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ): TransportInterface {
        if (!$resolved->useProxy) {
            return $this->esmtpTransports->from($resolved, $dispatcher, $logger);
        }

        $proxy = $this->proxySource->configuredProxy();
        if (null === $proxy) {
            throw IncompleteMailConfigurationException::proxyMissing();
        }

        return new CurlSmtpTransport($resolved, $proxy, $dispatcher, $logger);
    }

    public function forFallbackDsn(
        string $dsn,
        ?EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ): TransportInterface {
        return Transport::fromDsn($dsn, $dispatcher, $this->httpClient, $logger);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Proxy\ProxySettings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Builds the active mail transport, so sends and the tester agree on what would send:
 *  a resolved row becomes the plain EsmtpTransport, or the curl proxy transport when the
 *  row routes mail through the configured proxy (independent of the feed-egress switch);
 *  no row falls back to the env DSN. */
final readonly class ActiveMailTransportFactory
{
    public function __construct(
        private ProxySettings $proxySettings,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function forResolved(
        ResolvedMailTransport $resolved,
        ?EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ): TransportInterface {
        if (!$resolved->useProxy) {
            return EsmtpTransportBuilder::from($resolved, $dispatcher, $logger);
        }

        $proxy = $this->proxySettings->configuredProxy();
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

<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Proxy\ProxySettings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/** Builds the transport a resolved mail row asks for: the plain EsmtpTransport,
 *  or the curl proxy transport when the row routes through the egress proxy.
 *  The single place that decision lives, shared by real sends and the tester. */
final readonly class ActiveMailTransportFactory
{
    public function __construct(private ProxySettings $proxySettings)
    {
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
}

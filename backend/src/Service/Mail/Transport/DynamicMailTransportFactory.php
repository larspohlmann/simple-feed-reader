<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers the `dynamic://` scheme. FrameworkBundle autoconfigures any
 * TransportFactoryInterface with the mailer.transport_factory tag, so this joins
 * the transport chain and MAILER_DSN=dynamic://default resolves to it.
 */
final readonly class DynamicMailTransportFactory implements TransportFactoryInterface
{
    public function __construct(private DynamicMailTransport $transport)
    {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        return $this->transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return 'dynamic' === $dsn->getScheme();
    }
}

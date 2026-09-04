<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers the `dynamic://` scheme. Wired into the transport chain via the
 * `_instanceof` tag in config/services.yaml; FrameworkBundle does not auto-tag
 * a plain TransportFactoryInterface implementation.
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

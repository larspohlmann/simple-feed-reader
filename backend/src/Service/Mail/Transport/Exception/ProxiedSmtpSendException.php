<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport\Exception;

use App\Service\Fetch\ProxyHandshakeFailure;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A proxied SMTP send that failed at the curl layer, with the raw curl message
 * run through the same admin-facing explainer the fetch path and the proxy Test
 * button use — so a SOCKS5 reply code becomes a reason, not a bare byte (#880).
 */
final class ProxiedSmtpSendException extends TransportException
{
    public static function fromCurlError(string $curlError): self
    {
        return new self(sprintf('Proxied SMTP send failed: %s', ProxyHandshakeFailure::explain($curlError)));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** The REP byte of a SOCKS5 CONNECT reply (RFC 1928 §6). curl surfaces only the number; this knows its meaning. */
enum SocksReplyCode: int
{
    case GeneralFailure = 1;
    case NotAllowedByRuleset = 2;
    case NetworkUnreachable = 3;
    case HostUnreachable = 4;
    case ConnectionRefused = 5;
    case TtlExpired = 6;
    case CommandNotSupported = 7;
    case AddressTypeNotSupported = 8;

    public function meaning(): string
    {
        return match ($this) {
            self::GeneralFailure => 'the proxy reported a general failure',
            self::NotAllowedByRuleset => 'the proxy refused the request by its own rules — usually a rejected '
                . 'username and password, or a destination port it does not allow',
            self::NetworkUnreachable => 'the proxy could not reach the destination network',
            self::HostUnreachable => 'the proxy could not reach the destination host',
            self::ConnectionRefused => 'the destination refused the connection',
            self::TtlExpired => 'the connection expired on the way to the destination',
            self::CommandNotSupported => 'the proxy does not support the CONNECT command',
            self::AddressTypeNotSupported => 'the proxy does not support the address type it was given',
        };
    }

    /** A proxy cannot say "I do not do DNS": it reports the name as unreachable or rejects the address type. */
    public function canMeanTheProxyDoesNotResolveNames(): bool
    {
        return self::HostUnreachable === $this || self::AddressTypeNotSupported === $this;
    }
}

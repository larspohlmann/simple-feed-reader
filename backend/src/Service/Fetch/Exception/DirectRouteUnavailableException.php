<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/** A direct request after a proxy refusal is not allowed: there is no proxy, or it forbids falling back. */
final class DirectRouteUnavailableException extends \RuntimeException
{
    public static function withoutAProxy(string $url): self
    {
        return new self(sprintf('%s already went direct: no egress proxy is configured.', $url));
    }

    public static function withoutFallback(string $url): self
    {
        return new self(sprintf('%s may not go direct: the egress proxy forbids falling back.', $url));
    }
}

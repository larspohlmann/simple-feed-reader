<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Exception;

/** Refuses to persist an enabled row that could not send: it would accept every message and deliver none. */
final class IncompleteMailConfigurationException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function passwordMissing(): self
    {
        return new self('An enabled SMTP transport with a username needs a password, stored or provided.');
    }

    public static function transportMissing(): self
    {
        return new self('Enabling mail needs an SMTP host, because the environment has no fallback transport.');
    }

    public static function proxyMissing(): self
    {
        return new self('Mail is set to use the egress proxy, but no proxy is configured.');
    }
}

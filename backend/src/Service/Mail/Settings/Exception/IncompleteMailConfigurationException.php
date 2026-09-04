<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Exception;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to persist an enabled row that could not send: an authenticated
 * transport with no password on record, or no host while the env fallback is
 * null. Either would accept every message and deliver none, silently.
 */
final class IncompleteMailConfigurationException extends ApiException
{
    private function __construct(string $detail)
    {
        parent::__construct(
            'incomplete_mail_configuration',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Incomplete mail configuration',
            $detail,
        );
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

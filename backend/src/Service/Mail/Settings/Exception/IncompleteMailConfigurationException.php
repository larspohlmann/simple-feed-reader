<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Exception;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to persist an enabled, authenticated SMTP row with no password on
 * record. That row would resolve to a real, host-bearing transport that fails
 * every send — overriding a working env fallback with a broken one, silently.
 */
final class IncompleteMailConfigurationException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'incomplete_mail_configuration',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Incomplete mail configuration',
            'An enabled SMTP transport with a username needs a password, stored or provided.',
        );
    }
}

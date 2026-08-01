<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The web setup endpoint is closed: either no ADMIN_SETUP_SECRET is configured,
 * or an administrator already exists. Both answer 404 so a closed endpoint
 * reveals nothing about which of the two it is.
 */
final class SetupUnavailableException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'setup_unavailable',
            404,
            'Not found',
            'Setup is not available.',
        );
    }
}

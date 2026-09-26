<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/** The reason code the SPA receives in the failure redirect; the values are wire contract. */
enum OAuthCallbackFailure: string
{
    case AccessDenied = 'access_denied';
    case InvalidRequest = 'invalid_request';
    case InvalidState = 'invalid_state';
    case ExchangeFailed = 'exchange_failed';
}

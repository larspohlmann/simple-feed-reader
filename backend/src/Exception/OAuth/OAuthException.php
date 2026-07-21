<?php

declare(strict_types=1);

namespace App\Exception\OAuth;

use App\Exception\ApiException;

/**
 * Base for the OAuth flow's deliberate failures, so a caller can catch the
 * whole family — the callback endpoint turns any of these into a redirect
 * carrying an error code rather than a problem document, because at that point
 * in the flow the client is a browser following a 302, not the SPA's fetch().
 */
abstract class OAuthException extends ApiException
{
}

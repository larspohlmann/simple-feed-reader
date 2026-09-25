<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/**
 * The provider conversation produced no usable identity. One type for every cause on purpose: telling them apart
 * would hand a caller a probe into our configuration. The cause lives in $logDetail and $previous only.
 */
final class OAuthFailedException extends OAuthException
{
    public function __construct(public readonly string $logDetail, ?\Throwable $previous = null)
    {
        parent::__construct('The OAuth exchange failed.', previous: $previous);
    }
}

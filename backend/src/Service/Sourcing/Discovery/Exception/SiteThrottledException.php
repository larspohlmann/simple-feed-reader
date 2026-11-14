<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Discovery\Exception;

/** The site answered 429: the caller must not ask it again now, and must not read that as "no feed here". */
final class SiteThrottledException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The site asked us to slow down.', previous: $previous);
    }
}

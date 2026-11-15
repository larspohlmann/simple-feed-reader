<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Exception;

final class RedirectChainException extends FetchException
{
    /** The redirect target the chain was requesting when it failed; null when its first request failed. */
    public private(set) ?string $hopUrl = null;

    public static function afterRedirect(self $failure, string $hopUrl): self
    {
        $exception = new self($failure->getMessage(), previous: $failure->getPrevious());
        $exception->hopUrl = $hopUrl;

        return $exception;
    }
}

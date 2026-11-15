<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Exception;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RedirectChainException extends FetchException
{
    /** The redirect target the chain was requesting when it failed; null when its first request failed. */
    public private(set) ?string $hopUrl = null;

    public static function afterRedirect(self $failure, string $hopUrl): self
    {
        $exception = new self($failure->getMessage(), previous: $failure);
        $exception->hopUrl = $hopUrl;

        return $exception;
    }

    /** A timeout or a lost connection, as opposed to a refused hop, a loop or a missing Location. */
    public function causedByTransportFailure(): bool
    {
        for ($cause = $this->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TransportExceptionInterface) {
                return true;
            }
        }

        return false;
    }
}

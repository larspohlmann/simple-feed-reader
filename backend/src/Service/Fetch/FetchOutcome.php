<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;

/**
 * One feed's result inside a batch. Failure is a value here rather than a thrown
 * exception because a batch cannot throw for one feed without abandoning the
 * others still in flight; the caller unwraps and decides per feed.
 */
final readonly class FetchOutcome
{
    private function __construct(
        private ?FetchResponse $response,
        private ?FetchException $failure,
    ) {
    }

    public static function succeeded(FetchResponse $response): self
    {
        return new self($response, null);
    }

    public static function failed(FetchException $failure): self
    {
        return new self(null, $failure);
    }

    public function failure(): ?FetchException
    {
        return $this->failure;
    }

    /** @throws FetchException when this outcome is a failure */
    public function responseOrThrow(): FetchResponse
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        // Both properties are set together by the two factories, so a null
        // response here would mean the class was constructed past its own API.
        \assert(null !== $this->response);

        return $this->response;
    }
}

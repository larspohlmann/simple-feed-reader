<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Exception\FetchException;

/**
 * One request's result inside a batch. Failure is a value here rather than a thrown
 * exception because a batch cannot throw for one request without abandoning the
 * others still in flight; the caller unwraps and decides per request.
 */
final readonly class FetchOutcomeModel
{
    private function __construct(
        private ?FetchResponseModel $response,
        private ?ApiResponseModel $answer,
        private ?FetchException $failure,
    ) {
    }

    public static function succeeded(FetchResponseModel $response): self
    {
        return new self($response, null, null);
    }

    public static function answered(ApiResponseModel $answer): self
    {
        return new self(null, $answer, null);
    }

    public static function failed(FetchException $failure): self
    {
        return new self(null, null, $failure);
    }

    /** The dispatch gate kept the request off the wire. */
    public static function withheld(): self
    {
        return new self(null, null, null);
    }

    public function isWithheld(): bool
    {
        return null === $this->response && null === $this->answer && null === $this->failure;
    }

    public function failure(): ?FetchException
    {
        return $this->failure;
    }

    /** @throws FetchException when this outcome is a failure */
    public function responseOrThrow(): FetchResponseModel
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->response ?? throw new \LogicException('This outcome carries no feed response.');
    }

    /** @throws FetchException when this outcome is a failure */
    public function answerOrThrow(): ApiResponseModel
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->answer ?? throw new \LogicException('This outcome carries no API answer.');
    }
}

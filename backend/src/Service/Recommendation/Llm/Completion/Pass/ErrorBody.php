<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Pass;

use App\Service\Ai\Exception\ProviderUnreachableException;

/** One call's error status and as much of its body as a reason can sit in; a larger body ends the call at once. */
final class ErrorBody
{
    private const int MAXIMUM_BYTES = 16_384;

    private ?int $status = null;

    private string $collected = '';

    public function open(int $status): void
    {
        $this->status = $status;
    }

    public function isOpen(): bool
    {
        return null !== $this->status;
    }

    /** @throws ProviderUnreachableException once the body outgrows the bound, so no more of it is read */
    public function collect(string $content): void
    {
        if (\strlen($this->collected) + \strlen($content) > self::MAXIMUM_BYTES) {
            throw $this->failure();
        }
        $this->collected .= $content;
    }

    public function text(): string
    {
        return $this->collected;
    }

    public function failure(?string $reason = null): ProviderUnreachableException
    {
        return ProviderUnreachableException::answeredWithStatus(
            $this->status ?? throw new \LogicException('No error status was opened for this call.'),
            $reason,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Pass;

/** One call's error status and as much of its body as a reason can sit in; a larger body is not kept at all. */
final class ErrorBody
{
    private const int MAXIMUM_BYTES = 16_384;

    private ?int $status = null;

    private string $collected = '';

    private bool $overflowed = false;

    public function open(int $status): void
    {
        $this->status = $status;
    }

    public function isOpen(): bool
    {
        return null !== $this->status;
    }

    public function collect(string $content): void
    {
        if ($this->overflowed) {
            return;
        }
        if (\strlen($this->collected) + \strlen($content) > self::MAXIMUM_BYTES) {
            $this->overflowed = true;
            $this->collected = '';

            return;
        }
        $this->collected .= $content;
    }

    public function status(): int
    {
        return $this->status ?? throw new \LogicException('No error status was opened for this call.');
    }

    public function text(): string
    {
        return $this->collected;
    }
}

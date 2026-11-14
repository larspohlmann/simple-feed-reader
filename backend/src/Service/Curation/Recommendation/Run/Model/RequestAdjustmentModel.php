<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\RequestAdjustment;
use App\Enum\TokenLimitParameter;

/** What a fallback changed on the connection after a rejection, and the value the next request carries. */
final readonly class RequestAdjustmentModel
{
    private function __construct(public RequestAdjustment $setting, public ?string $value)
    {
    }

    public static function tokenParameter(TokenLimitParameter $parameter): self
    {
        return new self(RequestAdjustment::TokenParameter, $parameter->value);
    }

    public static function outputCap(int $tokens): self
    {
        return new self(RequestAdjustment::OutputCap, (string) $tokens);
    }

    public static function reasoningSuppressionDropped(): self
    {
        return new self(RequestAdjustment::ReasoningSuppression, null);
    }
}

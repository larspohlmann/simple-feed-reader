<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

/** OpenAI's `error.code` and `error.param`: what a refusal is about, stable where its message wording is not. */
final readonly class ProviderErrorIdentifiersModel
{
    public function __construct(public ?string $code, public ?string $param)
    {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public function names(string $code, string $param): bool
    {
        return $code === $this->code && $param === $this->param;
    }
}

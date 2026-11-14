<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

/** What a recommendation run may freeze: a profile (or none, when there is no history), not yet, or a failure. */
final readonly class RunProfileModel
{
    private function __construct(
        public RunProfileState $state,
        public ?string $text,
        public ?string $error,
    ) {
    }

    public static function ready(?string $text): self
    {
        return new self(RunProfileState::Ready, $text, null);
    }

    public static function building(): self
    {
        return new self(RunProfileState::Building, null, null);
    }

    public static function failed(string $error): self
    {
        return new self(RunProfileState::Failed, null, $error);
    }
}

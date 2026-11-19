<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

final readonly class CredentialReplacementModel
{
    public function __construct(#[\SensitiveParameter] public string $secret, public ?string $secondFactor)
    {
    }
}

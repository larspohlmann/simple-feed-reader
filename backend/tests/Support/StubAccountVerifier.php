<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Social\AccountVerifier\AccountVerifierInterface;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final class StubAccountVerifier implements AccountVerifierInterface
{
    public ?SocialCredentialsModel $lastCredentials = null;

    public function __construct(private readonly VerifiedAccountModel|\Throwable $outcome)
    {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $this->lastCredentials = $credentials;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

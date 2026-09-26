<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

use App\Service\OAuth\OAuthCallbackFailure;

final class OAuthCallbackRefusedException extends OAuthException
{
    public function __construct(public readonly OAuthCallbackFailure $failure)
    {
        parent::__construct(\sprintf('The OAuth callback was refused: %s.', $failure->value));
    }
}

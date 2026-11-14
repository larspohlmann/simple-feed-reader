<?php

declare(strict_types=1);

namespace App\Service\Identity\OAuth\Exception;

use App\Service\Identity\OAuth\Model\OAuthCallbackFailure;

final class OAuthCallbackRefusedException extends OAuthException
{
    public function __construct(public readonly OAuthCallbackFailure $failure)
    {
        parent::__construct(\sprintf('The OAuth callback was refused: %s.', $failure->value));
    }
}

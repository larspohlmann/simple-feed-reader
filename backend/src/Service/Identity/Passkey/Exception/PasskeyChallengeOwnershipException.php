<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** The redeemed challenge names another account: this is what binds a registration challenge to its owner. */
final class PasskeyChallengeOwnershipException extends \RuntimeException
{
}

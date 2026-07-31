<?php

declare(strict_types=1);

namespace App\Service\Admin\Exception;

/**
 * UserStatistics resolves subscriptions and tags by user id, so a User that
 * was never persisted — getId() still null — cannot be looked up. Without
 * this guard the id would silently cast to 0 and the service would return a
 * plausible-looking, empty footprint for an account that does not exist,
 * instead of failing.
 */
final class UnpersistedUserException extends \RuntimeException
{
    public static function forUser(): self
    {
        return new self('Cannot build a footprint for a user that has not been persisted yet.');
    }
}

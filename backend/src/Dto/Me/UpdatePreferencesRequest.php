<?php

declare(strict_types=1);

namespace App\Dto\Me;

/**
 * The value is required, with no default, for the same reason
 * UpdateLocaleRequest refuses an unsupported locale: a preference that
 * degrades quietly to a default is indistinguishable from one the user set,
 * and an empty body must not be able to turn the feature off silently.
 *
 * No validation attributes: the promoted `bool` type plus MapRequestPayload's
 * denormalization already reject a missing or non-boolean value with a 422
 * before the validator runs, so an Assert here would be dead decoration.
 */
final readonly class UpdatePreferencesRequest
{
    public function __construct(
        public bool $scrapeFallbackEnabled,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

final class BlueskyProfileLabels
{
    private const string HIDDEN_FROM_LOGGED_OUT = '!no-unauthenticated';

    /** Only the profile's own label is its choice: a labeller may put the same value on someone else. */
    public static function hidesFromLoggedOut(JsonNodeModel $profile): bool
    {
        $did = $profile->string('did');
        foreach ($profile->nodes('labels') as $label) {
            if (
                self::HIDDEN_FROM_LOGGED_OUT === $label->string('val')
                && null !== $did
                && $did === $label->string('src')
                && !$label->isTrue('neg')
            ) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}

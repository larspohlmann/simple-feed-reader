<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\HiddenProfileModel;

final class BlueskyProfileLabels
{
    private const string HIDDEN_FROM_LOGGED_OUT = '!no-unauthenticated';

    /**
     * @param array<string, JsonNodeModel> $profiles AppView profile views, by actor
     *
     * @return array<string, HiddenProfileModel> the ones hiding from logged-out readers, by the same actor
     */
    public static function hiddenAmong(array $profiles): array
    {
        $hidden = [];
        foreach ($profiles as $actor => $profile) {
            if (self::hidesFromLoggedOut($profile)) {
                $hidden[$actor] = self::hiddenProfileOf($profile);
            }
        }

        return $hidden;
    }

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

    private static function hiddenProfileOf(JsonNodeModel $profile): HiddenProfileModel
    {
        $did = (string) $profile->string('did');

        return new HiddenProfileModel($did, BlueskyAuthor::name($profile) ?? $did);
    }

    private function __construct()
    {
    }
}

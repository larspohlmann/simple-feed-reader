<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\ContextReferenceModel;

final class BlueskyReplyReferences
{
    public static function of(JsonNodeModel $record, SocialNotificationKind $kind): ContextReferenceModel
    {
        return match ($kind) {
            SocialNotificationKind::Reply => self::replyOf($record->node('reply')),
            SocialNotificationKind::Quote => new ContextReferenceModel(self::quotedUri($record->node('embed'))),
            default => new ContextReferenceModel(),
        };
    }

    private static function replyOf(JsonNodeModel $reply): ContextReferenceModel
    {
        $parent = $reply->node('parent')->string('uri');
        $root = $reply->node('root')->string('uri');

        return new ContextReferenceModel($parent, $root === $parent ? null : $root);
    }

    private static function quotedUri(JsonNodeModel $embed): ?string
    {
        $record = $embed->node('record');

        return $embed->type() === 'app.bsky.embed.recordWithMedia'
            ? $record->node('record')->string('uri')
            : $record->string('uri');
    }

    private function __construct()
    {
    }
}

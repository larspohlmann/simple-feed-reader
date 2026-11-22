<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

use App\Service\Social\Social\Model\PostContextModel;

/** What the composer sends: a reply, a quote, or a post of a shared link. */
enum ComposerKind: string
{
    case Reply = 'reply';
    case Quote = 'quote';
    case Link = 'link';

    public function isAllowedBy(PostContextModel $context): bool
    {
        return match ($this) {
            self::Reply => $context->canReply,
            self::Quote => $context->canQuote,
            self::Link => true,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

use App\Service\Social\Social\Model\PostContextModel;

/** What the composer sends: a reply, a quote, a post of a shared link, or a post of text alone. */
enum ComposerKind: string
{
    case Reply = 'reply';
    case Quote = 'quote';
    case Link = 'link';
    case Post = 'post';

    public function isResponse(): bool
    {
        return self::Reply === $this || self::Quote === $this;
    }

    public function isAllowedBy(PostContextModel $context): bool
    {
        return match ($this) {
            self::Reply => $context->canReply,
            self::Quote => $context->canQuote,
            self::Link, self::Post => true,
        };
    }
}

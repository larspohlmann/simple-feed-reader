<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What replying to or quoting a post needs to know about it beyond its id. */
final readonly class ResponseContextModel
{
    /**
     * @param string|null             $authorIdentity the author as a SocialAccount identity: a DID, or user@host
     * @param PostReferenceModel|null $threadRoot     the Bluesky thread's first post; null when the post is it
     * @param string|null             $visibility     the Mastodon status's visibility; null on Bluesky
     */
    public function __construct(
        public ?string $authorIdentity = null,
        public ?PostReferenceModel $threadRoot = null,
        public ?string $visibility = null,
        public bool $canReply = true,
        public bool $canQuote = true,
        public PostPreviewModel $preview = new PostPreviewModel(),
    ) {
    }

    public function allows(ResponseKind $kind): bool
    {
        return match ($kind) {
            ResponseKind::Reply => $this->canReply,
            ResponseKind::Quote => $this->canQuote,
        };
    }
}

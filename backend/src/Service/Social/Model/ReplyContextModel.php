<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What answering a post needs to know about it beyond its id. */
final readonly class ReplyContextModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string|null             $authorIdentity the author as a SocialAccount identity: a DID, or user@host
     * @param PostReferenceModel|null $threadRoot     the Bluesky thread's first post; null when the post is it
     * @param string|null             $visibility     the Mastodon status's visibility; null on Bluesky
     */
    public function __construct(
        public ?string $authorIdentity = null,
        public ?PostReferenceModel $threadRoot = null,
        public ?string $visibility = null,
        public bool $canReply = true,
    ) {
    }
}

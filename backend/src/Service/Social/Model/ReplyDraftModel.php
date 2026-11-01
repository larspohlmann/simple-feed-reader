<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What the user wrote in the composer; the Mastodon-only parts are ignored on Bluesky. */
final readonly class ReplyDraftModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string|null $language       a language code such as "de"
     * @param string|null $idempotencyKey minted when the composer opened, so a resent reply is published once
     */
    public function __construct(
        public string $text,
        public ?string $visibility = null,
        public ?string $contentWarning = null,
        public ?string $language = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}

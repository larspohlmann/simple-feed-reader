<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

/** What the user wrote in the composer; each network ignores the parts only Mastodon takes. */
final readonly class PostDraftModel
{
    /** From the most open to the most closed. */
    public const array VISIBILITIES = ['public', 'unlisted', 'private', 'direct'];

    /**
     * @param string|null $language       a language code such as "de"
     * @param string|null $idempotencyKey minted when the composer opened, so a resent post is published once
     */
    public function __construct(
        public string $text,
        public ?string $visibility = null,
        public ?string $contentWarning = null,
        public ?string $language = null,
        public ?string $idempotencyKey = null,
    ) {
    }

    #[\NoDiscard]
    public function withText(string $text): self
    {
        return clone($this, ['text' => $text]);
    }
}

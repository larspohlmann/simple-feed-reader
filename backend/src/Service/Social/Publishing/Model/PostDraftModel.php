<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

/** What the user wrote in the composer; each network ignores the parts only Mastodon or only Lemmy takes. */
final readonly class PostDraftModel
{
    /** From the most open to the most closed. */
    public const array VISIBILITIES = ['public', 'unlisted', 'private', 'direct'];

    /**
     * @param string|null $language       a language code such as "de"
     * @param string|null $idempotencyKey minted when the composer opened, so a resent reply is published once
     * @param string|null $title          the Lemmy link post's title
     * @param int|null    $communityId    the Lemmy community a link post goes to
     */
    public function __construct(
        public string $text,
        public ?string $visibility = null,
        public ?string $contentWarning = null,
        public ?string $language = null,
        public ?string $idempotencyKey = null,
        public ?string $title = null,
        public ?int $communityId = null,
    ) {
    }

    #[\NoDiscard]
    public function withText(string $text): self
    {
        return clone($this, ['text' => $text]);
    }
}

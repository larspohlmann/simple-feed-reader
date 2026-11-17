<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

/** What the composer starts from on a network: its length limit, visibility, mentions and Lemmy link fields. */
final readonly class ComposerDefaultsModel
{
    /**
     * @param string|null  $visibility the Mastodon visibility to start from; null on Bluesky
     * @param list<string> $mentions   handles with their "@", in the order they open the text
     * @param LemmyLinkModel|null $lemmyLink the communities and title a Lemmy link post chooses from; null elsewhere
     */
    public function __construct(
        public int $maxCharacters,
        public ?string $visibility = null,
        public array $mentions = [],
        public ?LemmyLinkModel $lemmyLink = null,
    ) {
    }

    #[\NoDiscard]
    public function withMaxCharacters(int $maxCharacters): self
    {
        return clone($this, ['maxCharacters' => $maxCharacters]);
    }

    #[\NoDiscard]
    public function withoutMentions(): self
    {
        return clone($this, ['mentions' => []]);
    }
}

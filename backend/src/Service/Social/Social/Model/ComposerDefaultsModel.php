<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

/** What the composer starts from on a network: its length limit, visibility, mentions and link post fields. */
final readonly class ComposerDefaultsModel
{
    /**
     * @param string|null               $visibility the Mastodon visibility to start from; null on Bluesky
     * @param list<string>              $mentions   handles with their "@", in the order they open the text
     * @param LinkPostChoicesModel|null $linkPost   the communities and title a link post chooses from; null elsewhere
     */
    public function __construct(
        public int $maxCharacters,
        public ?string $visibility = null,
        public array $mentions = [],
        public ?LinkPostChoicesModel $linkPost = null,
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

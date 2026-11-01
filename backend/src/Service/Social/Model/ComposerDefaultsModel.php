<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What the composer starts from on a network: its length limit, visibility and the mentions it prefills. */
final readonly class ComposerDefaultsModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string|null  $visibility the Mastodon visibility to start from; null on Bluesky
     * @param list<string> $mentions   handles with their "@", in the order they open the text
     */
    public function __construct(
        public int $maxCharacters,
        public ?string $visibility = null,
        public array $mentions = [],
    ) {
    }
}

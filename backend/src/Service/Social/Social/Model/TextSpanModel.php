<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A run of a post's text, addressed in UTF-8 bytes as Bluesky facets are. */
final readonly class TextSpanModel
{
    /**
     * @noinspection AutowireWrongClass Built with new, never autowired
     *
     * @param string $value the link, the tag without its "#", or the handle without its "@"
     */
    public function __construct(public int $byteStart, public int $byteEnd, public string $value)
    {
    }
}

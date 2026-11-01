<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A reply the network took: its address in a comments thread, and where a browser shows it. */
final readonly class PublishedReplyModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(public string $address, public ?string $url)
    {
    }
}

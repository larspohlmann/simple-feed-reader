<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A post the network took: its address, and where a browser shows it. */
final readonly class PublishedPostModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(public string $address, public ?string $url)
    {
    }
}

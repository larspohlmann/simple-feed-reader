<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A Bluesky strong reference: the post's AT URI and the CID of the record it names. */
final readonly class PostReferenceModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(public string $uri, public string $contentId)
    {
    }
}

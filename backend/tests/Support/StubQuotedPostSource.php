<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\QuotedPostContent;
use App\Service\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\LinkCard\QuotedPostSource\QuotedPostSourceInterface;

final class StubQuotedPostSource implements QuotedPostSourceInterface
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, QuotedPostContent> $quotesByAddress */
    public function __construct(private readonly array $quotesByAddress = [])
    {
    }

    public function quotedPostAt(string $address): QuotedPostContent
    {
        $this->asked[] = $address;

        return $this->quotesByAddress[$address]
            ?? throw new QuotedPostUnavailableException('No quote stubbed for ' . $address);
    }
}

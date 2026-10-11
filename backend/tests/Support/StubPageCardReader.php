<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;

/** Cards every page "Card for <url>"; a URL containing /missing fails to fetch. */
final class StubPageCardReader implements PageCardReaderInterface
{
    private int $reads = 0;

    /** @param ?\Closure(): void $whileReading runs inside every read, as a slow page or a rival worker would */
    public function __construct(private readonly ?\Closure $whileReading = null)
    {
    }

    public function read(string $sharedUrl): PageCardModel
    {
        ++$this->reads;
        if ($this->whileReading !== null) {
            ($this->whileReading)();
        }
        if (str_contains($sharedUrl, '/missing')) {
            throw new PageFetchException('Not found: ' . $sharedUrl);
        }

        return new PageCardModel(
            new LinkCardContent($sharedUrl, 'Card for ' . $sharedUrl, null, null, 'example.com'),
            null,
        );
    }

    public function reads(): int
    {
        return $this->reads;
    }
}

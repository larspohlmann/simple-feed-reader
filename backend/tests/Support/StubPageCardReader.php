<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\Model\PageIdentityModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;

/** Cards every page "Card for <url>", one page whatever its trackers; a URL containing /missing fails to fetch. */
final class StubPageCardReader implements PageCardReaderInterface
{
    private int $reads = 0;

    /** @var list<string> */
    private array $readUrls = [];

    /** @param ?\Closure(): void $whileReading runs inside every read, as a slow page or a rival worker would */
    public function __construct(private readonly ?\Closure $whileReading = null)
    {
    }

    public function read(string $sharedUrl): PageCardModel
    {
        ++$this->reads;
        $this->readUrls[] = $sharedUrl;
        if ($this->whileReading !== null) {
            ($this->whileReading)();
        }
        if (str_contains($sharedUrl, '/missing')) {
            throw new PageFetchException('Not found: ' . $sharedUrl);
        }

        return new PageCardModel(
            new LinkCardContent($sharedUrl, 'Card for ' . $sharedUrl, null, null, 'example.com'),
            null,
            new PageIdentityModel($sharedUrl, null, 'Card for ' . TrackingFreeUrl::stripTracking($sharedUrl)),
        );
    }

    /** @return list<string> */
    public function readUrls(): array
    {
        return $this->readUrls;
    }

    public function reads(): int
    {
        return $this->reads;
    }
}

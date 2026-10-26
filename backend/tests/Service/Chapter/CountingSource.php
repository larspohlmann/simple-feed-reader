<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Service\Chapter\EmbedDescriptionSource\EmbedDescriptionSourceInterface;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\EmbedAddressModel;

final class CountingSource implements EmbedDescriptionSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $description, private readonly ?int $failingStatus = null)
    {
    }

    public static function provider(): string
    {
        return 'counting';
    }

    public function descriptionOf(EmbedAddressModel $embed): string
    {
        ++$this->calls;
        if ($this->failingStatus !== null) {
            throw EmbedDescriptionUnreachableException::answered($embed, $this->failingStatus);
        }

        return $this->description;
    }
}

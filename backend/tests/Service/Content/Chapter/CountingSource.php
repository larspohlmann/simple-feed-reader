<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Chapter;

use App\Service\Content\Chapter\EmbedDetailsSource\EmbedDetailsSourceInterface;
use App\Service\Content\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Content\Chapter\Model\EmbedAddressModel;
use App\Service\Content\Chapter\Model\EmbedDescriptionModel;

final class CountingSource implements EmbedDetailsSourceInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly EmbedDescriptionModel $described,
        private readonly ?int $failingStatus = null,
    ) {
    }

    public static function describing(string $description): self
    {
        return new self(new EmbedDescriptionModel(null, null, $description, null));
    }

    public static function failingWith(int $status): self
    {
        return new self(new EmbedDescriptionModel(null, null, '', null), $status);
    }

    public static function provider(): string
    {
        return 'counting';
    }

    public function detailsOf(EmbedAddressModel $embed): EmbedDescriptionModel
    {
        ++$this->calls;
        if ($this->failingStatus !== null) {
            throw EmbedDescriptionUnreachableException::answered($embed, $this->failingStatus);
        }

        return $this->described;
    }
}

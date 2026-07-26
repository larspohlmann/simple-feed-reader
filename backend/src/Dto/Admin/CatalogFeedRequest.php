<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\SourceFormat;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogFeedRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $categoryId = 0,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $title = '',
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 750)]
        public string $url = '',
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 750)]
        public ?string $siteUrl = null,
        #[Assert\Length(max: 255)]
        public ?string $description = null,
        #[Assert\Choice(choices: [SourceFormat::XML, SourceFormat::SCRAPED])]
        public string $sourceFormat = SourceFormat::XML,
        public bool $enabled = true,
        /** Locked rows are the admin's: an import will neither overwrite nor delete them. */
        public bool $locked = true,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\SocialImportFeedModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportFeedItem
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 750)]
        public string $feedUrl = '',
        #[Assert\Length(max: 512)]
        public string $title = '',
    ) {
    }

    public function toFeed(): SocialImportFeedModel
    {
        $title = trim($this->title);

        return new SocialImportFeedModel(trim($this->feedUrl), '' === $title ? null : $title);
    }
}

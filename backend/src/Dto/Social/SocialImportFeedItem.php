<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\Feed;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportFeedItem
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 750)]
        public string $feedUrl = '',
        #[Assert\Length(max: 512)]
        public ?string $title = null,
        public ?string $avatarUrl = null,
    ) {
    }

    public function toFeed(): SocialImportFeedModel
    {
        $title = trim($this->title ?? '');

        return new SocialImportFeedModel(
            trim($this->feedUrl),
            '' === $title ? null : $title,
            AbsoluteHttpUrl::orNullWithin($this->avatarUrl, Feed::IMAGE_URL_MAX),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportCollectionItem
{
    public function __construct(
        #[Assert\Choice(choices: [
            SocialFeedKind::Home->value,
            SocialFeedKind::UserList->value,
            SocialFeedKind::CustomFeed->value,
        ])]
        public string $kind = '',
        #[Assert\Length(max: 512)]
        public ?string $id = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $name = '',
    ) {
    }

    public function toPrivateFeed(): PrivateFeedModel
    {
        $id = trim($this->id ?? '');

        return PrivateFeedModel::of(SocialFeedKind::from($this->kind), '' === $id ? null : $id, trim($this->name));
    }
}

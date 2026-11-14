<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\PrivateFeedModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportCollectionItem
{
    public function __construct(
        #[Assert\Choice(callback: 'clientKinds')]
        public string $kind = '',
        #[Assert\Length(max: 512)]
        public ?string $id = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $name = '',
    ) {
    }

    /** @return list<string> */
    public static function clientKinds(): array
    {
        $collectionKinds = array_filter(
            SocialFeedKind::cases(),
            static fn (SocialFeedKind $kind): bool => !$kind->followsOnePerson(),
        );

        return array_values(array_map(static fn (SocialFeedKind $kind): string => $kind->value, $collectionKinds));
    }

    public function toPrivateFeed(): PrivateFeedModel
    {
        return PrivateFeedModel::of(SocialFeedKind::from($this->kind), trim($this->id ?? ''), trim($this->name));
    }
}

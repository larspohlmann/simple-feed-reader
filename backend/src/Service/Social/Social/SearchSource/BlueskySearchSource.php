<?php

declare(strict_types=1);

namespace App\Service\Social\Social\SearchSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\BlueskySessions;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\BlueskySearchPage;
use App\Service\Social\Social\Support\SocialSearchPaths;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskySearchSource implements SearchSourceInterface
{
    public function __construct(private BlueskySessions $sessions)
    {
    }

    public function fetchNewest(SocialAccount $account, string $query): TimelinePageModel
    {
        return BlueskySearchPage::of(
            BlueskySessions::accepted($this->sessions->askAs($account, SocialSearchPaths::bluesky($query))),
            $account->getIdentity(),
            $query,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskySessions;
use App\Service\Social\Bluesky\Support\BlueskySearchPage;
use App\Service\Social\Bluesky\Support\BlueskySearchPath;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\SearchSource\SearchSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskySearchSource implements SearchSourceInterface
{
    public function __construct(private BlueskySessions $sessions)
    {
    }

    public function fetchNewest(SocialAccount $account, string $query): TimelinePageModel
    {
        return BlueskySearchPage::fromAnswer(
            BlueskySessions::accepted($this->sessions->askAs($account, BlueskySearchPath::buildPath($query))),
            $account->getIdentity(),
            $query,
        );
    }
}

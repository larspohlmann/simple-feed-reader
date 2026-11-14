<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\SocialSearch\SocialSearchInterface;

final class StubSocialSearch implements SocialSearchInterface
{
    public ?string $query = null;

    public function __construct(private readonly TimelinePageModel $page)
    {
    }

    public function newest(SocialAccount $account, string $query): TimelinePageModel
    {
        $this->query = $query;

        return $this->page;
    }
}

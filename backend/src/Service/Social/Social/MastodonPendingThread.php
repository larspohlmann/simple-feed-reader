<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Share\PendingThread\PendingThreadInterface;
use App\Service\Social\Social\Support\MastodonStatusAddress;

final readonly class MastodonPendingThread implements PendingThreadInterface
{
    public function __construct(private SocialPostAddresses $postAddresses)
    {
    }

    public function awaitsThread(Entry $entry): bool
    {
        $address = $this->postAddresses->findPost($entry);

        return SocialNetwork::Mastodon === $address?->network
            && null !== MastodonStatusAddress::ofOrNull($address->address)
            && !SourceFormat::isSocial($entry->getFeed()->getSourceFormat());
    }
}

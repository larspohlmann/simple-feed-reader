<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Model\SocialFeedAddressModel;

final class SocialFeedClassifier
{
    public static function parsePrivateAddress(Feed $feed): ?SocialFeedAddressModel
    {
        return SourceFormat::isSocial($feed->getSourceFormat()) ? SocialFeedUrl::parseAddress($feed->getUrl()) : null;
    }

    private function __construct()
    {
    }
}

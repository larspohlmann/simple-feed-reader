<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialLikeResultModel
{
    /** @param string $likeId the id the network gave the like, which PostLikerInterface::unlike() takes back */
    public function __construct(public string $likeId)
    {
    }
}

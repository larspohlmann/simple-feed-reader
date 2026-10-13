<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What a network answered to a like; a fresh like count joins the id here when counts arrive (ruling 13). */
final readonly class SocialLikeResultModel
{
    /** @param string $likeId the id the network gave the like, which PostLikerInterface::unlike() takes back */
    public function __construct(public string $likeId)
    {
    }
}

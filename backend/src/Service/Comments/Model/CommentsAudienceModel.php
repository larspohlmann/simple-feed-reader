<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

use App\Entity\SocialAccount;

final readonly class CommentsAudienceModel
{
    public function __construct(public string $scope, public ?SocialAccount $actingAccount = null)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\LemmyThreadReader;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\LemmyPostModel;

interface LemmyThreadReaderInterface
{
    public function readerFor(User $user): ?SocialAccount;

    /**
     * @return string the comment list the account's own instance gave for the post
     *
     * @throws CommentsUnavailableException
     * @throws CommentsThrottledException
     */
    public function threadAs(SocialAccount $account, LemmyPostModel $post): string;
}

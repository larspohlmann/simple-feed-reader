<?php

declare(strict_types=1);

namespace App\Service\Social\ReplyThreadSource;

use App\Entity\SocialAccount;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Pass\ReplyNode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_reply_thread_source')]
interface ReplyThreadSourceInterface
{
    public static function network(): string;

    /**
     * The thread as the account's own server shows it to the account.
     *
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialTokenScopeMissingException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel;

    /**
     * The thread anyone may read.
     *
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function inPublic(string $address): CommentsAnswerModel;

    /**
     * @return list<ReplyNode>
     *
     * @throws CommentsUnavailableException
     */
    public function repliesIn(string $address, string $answer): array;
}

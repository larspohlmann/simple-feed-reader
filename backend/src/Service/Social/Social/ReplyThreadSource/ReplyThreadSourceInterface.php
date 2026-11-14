<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ReplyThreadSource;

use App\Entity\SocialAccount;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Pass\ReplyNode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_reply_thread_source')]
interface ReplyThreadSourceInterface
{
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

    /** How long the network asked us not to ask for a public thread; 0 when it did not. */
    public function publicThreadThrottledForSeconds(): int;

    /**
     * @return list<ReplyNode>
     *
     * @throws CommentsUnavailableException
     */
    public function repliesIn(string $address, string $answer): array;

    /** The author's own reply, from a timeline's post view, as a thread fold stores it. */
    public function selfReplyOf(JsonNodeModel $post): EntryCommentModel;
}

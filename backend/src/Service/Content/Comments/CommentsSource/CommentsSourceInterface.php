<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\CommentsAudienceModel;
use App\Service\Content\Comments\Model\CommentsPageModel;
use App\Service\Content\Comments\Pass\CommentsRequest;
use App\Service\Foundation\RateLimit\Exception\RateLimitedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.comments_source')]
interface CommentsSourceInterface
{
    public function serves(Entry $entry): bool;

    /** Who gets the same answer: the cache scope (a feed URL; a post plus the viewing account). */
    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel;

    /**
     * @throws CommentsThrottledException
     * @throws CommentsUnavailableException
     * @throws RateLimitedException
     */
    public function fetch(CommentsRequest $request): CommentsAnswerModel;

    /** @throws CommentsUnavailableException */
    public function read(CommentsRequest $request, string $answer): CommentsPageModel;
}

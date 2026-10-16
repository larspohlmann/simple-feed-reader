<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\RateLimit\Exception\RateLimitedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.comments_source')]
interface CommentsSourceInterface
{
    public function serves(Entry $entry): bool;

    /** Who gets the same answer: the cache scope (a feed URL; a post plus the viewing account). */
    public function audienceOf(User $user, Entry $entry): string;

    /**
     * @throws CommentsThrottledException
     * @throws CommentsUnavailableException
     * @throws RateLimitedException
     */
    public function fetch(User $user, Entry $entry): CommentsAnswerModel;

    /** @throws CommentsUnavailableException */
    public function read(Entry $entry, string $answer): CommentsPageModel;
}

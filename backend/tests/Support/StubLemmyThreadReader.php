<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Service\Content\Comments\LemmyThreadReader\LemmyThreadReaderInterface;
use App\Service\Content\Comments\Model\LemmyPostModel;

final class StubLemmyThreadReader implements LemmyThreadReaderInterface
{
    /** @var list<LemmyPostModel> */
    public array $postsRead = [];

    public function __construct(
        private readonly ?SocialAccount $account = null,
        private readonly string|\Throwable $thread = '',
    ) {
    }

    public function readerFor(User $user): ?SocialAccount
    {
        return $this->account;
    }

    public function threadAs(SocialAccount $account, LemmyPostModel $post): string
    {
        $this->postsRead[] = $post;

        return $this->thread instanceof \Throwable ? throw $this->thread : $this->thread;
    }
}

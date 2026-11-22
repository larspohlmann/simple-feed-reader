<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing\Model;

use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class PublicationModelTest extends TestCase
{
    public function testConstructorCarriesNothingElse(): void
    {
        $draft = new PostDraftModel('Hallo');

        $publication = new PublicationModel($draft);

        self::assertSame($draft, $publication->draft);
        self::assertNull($publication->inReplyTo);
        self::assertNull($publication->embed);
        self::assertNull($publication->lemmy);
    }

    public function testWithersReturnACopy(): void
    {
        $original = new PublicationModel(new PostDraftModel('Hallo'));
        $post = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110');

        $reply = $original->withReplyTo($post);

        self::assertSame($post, $reply->inReplyTo);
        self::assertNull($original->inReplyTo);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Social\Bluesky\BlueskyComposerDefaults;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;

final class BlueskyComposerDefaultsTest extends TestCase
{
    public function testEveryPublicationHolds300Graphemes(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        $standalone = PublicationModel::fromDraft(new PostDraftModel(''));
        $post = SocialPostModel::onBluesky($account, 'at://did:plc:ann/app.bsky.feed.post/1', 'cid');
        $publications = [
            $standalone,
            $standalone->withEmbed(new LinkEmbedModel('https://example.com', 'A title')),
            $standalone->withReplyTo($post),
        ];

        foreach ($publications as $publication) {
            self::assertEquals(
                new ComposerDefaultsModel(300),
                (new BlueskyComposerDefaults())
                    ->forPublication($account, $publication, static fn (): ?SocialPostModel => null),
            );
        }
    }
}

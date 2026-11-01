<?php

declare(strict_types=1);

namespace App\Service\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\BlueskyFacets;
use App\Service\Social\BlueskyExternalEmbeds;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;
use App\Service\Social\Support\BlueskyStrongRef;

final readonly class BlueskyPostWriter implements PostWriterInterface
{
    public const int MAX_GRAPHEMES = 300;
    private const string QUOTE_EMBED = 'app.bsky.embed.record';

    public function __construct(
        private BlueskyRecords $records,
        private BlueskyFacets $facets,
        private BlueskyExternalEmbeds $externalEmbeds,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['reply' => self::replyRefsOf($target)]);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, ['embed' => [
            '$type' => self::QUOTE_EMBED,
            'record' => BlueskyStrongRef::of($target),
        ]]);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        self::assertFits($draft);

        return $this->published($account, $draft, ['embed' => $this->externalEmbeds->embedOf($account, $url)]);
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::postOrNull($ownPost->postId) ?? '');
        if (null === $recordKey) {
            throw new SocialPostRefusedException('The address names no Bluesky post.');
        }

        $this->records->delete($ownPost->account, BlueskyRecords::POST_COLLECTION, $recordKey);
    }

    /** @param array<string, mixed> $attachment */
    private function published(SocialAccount $account, PostDraftModel $draft, array $attachment): PublishedPostModel
    {
        self::assertFits($draft);

        $uri = $this->records->createPost($account, [
            'text' => $draft->text,
            ...$attachment,
            ...$this->facetsOf($draft->text),
            ...(null === $draft->language ? [] : ['langs' => [$draft->language]]),
        ]);

        return new PublishedPostModel($uri, AtPostUri::webUrl($uri));
    }

    /** @throws SocialPostTooLongException */
    private static function assertFits(PostDraftModel $draft): void
    {
        if (grapheme_strlen($draft->text) > self::MAX_GRAPHEMES) {
            throw new SocialPostTooLongException(sprintf('A Bluesky post holds %d characters.', self::MAX_GRAPHEMES));
        }
    }

    /** @return array{root: array{uri: string, cid: ?string}, parent: array{uri: string, cid: ?string}} */
    private static function replyRefsOf(SocialPostModel $target): array
    {
        $parent = BlueskyStrongRef::of($target);
        $root = $target->responseContext->threadRoot;

        return [
            'root' => null === $root ? $parent : ['uri' => $root->uri, 'cid' => $root->contentId],
            'parent' => $parent,
        ];
    }

    /** @return array{facets?: non-empty-list<mixed>} */
    private function facetsOf(string $text): array
    {
        $facets = $this->facets->of($text);

        return [] === $facets ? [] : ['facets' => $facets];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Social\PostWriter;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\BlueskyFacets;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\PostDraftModel;
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
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target, $draft, ['reply' => self::replyRefsOf($target)]);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target, $draft, ['embed' => [
            '$type' => self::QUOTE_EMBED,
            'record' => BlueskyStrongRef::of($target),
        ]]);
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::postOrNull($ownPost->postId) ?? '');
        if (null === $recordKey) {
            throw new SocialPostRefusedException('The address names no Bluesky post.');
        }

        $this->records->delete($ownPost->account, BlueskyRecords::POST_COLLECTION, $recordKey);
    }

    /** @param array<string, mixed> $attachment how the post hangs on its target: a reply's refs or a quote's embed */
    private function published(SocialPostModel $target, PostDraftModel $draft, array $attachment): PublishedPostModel
    {
        if ((int) grapheme_strlen($draft->text) > self::MAX_GRAPHEMES) {
            throw new SocialPostTooLongException(sprintf('A Bluesky post holds %d characters.', self::MAX_GRAPHEMES));
        }

        $uri = $this->records->createPost($target->account, [
            'text' => $draft->text,
            ...$attachment,
            ...$this->facetsOf($draft->text),
            ...(null === $draft->language ? [] : ['langs' => [$draft->language]]),
        ]);

        return new PublishedPostModel($uri, AtPostUri::webUrl($uri));
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

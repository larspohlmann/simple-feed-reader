<?php

declare(strict_types=1);

namespace App\Service\Social\PostReplier;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Social\BlueskyFacets;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialReplyTooLongException;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;

final readonly class BlueskyPostReplier implements PostReplierInterface
{
    public const int MAX_GRAPHEMES = 300;

    public function __construct(
        private BlueskyRecords $records,
        private BlueskyFacets $facets,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel
    {
        if ((int) grapheme_strlen($draft->text) > self::MAX_GRAPHEMES) {
            throw new SocialReplyTooLongException(sprintf('A Bluesky post holds %d characters.', self::MAX_GRAPHEMES));
        }

        $uri = $this->records->createPost($target->account, [
            'text' => $draft->text,
            'reply' => self::replyRefsOf($target),
            ...$this->facetsOf($draft->text),
            ...(null === $draft->language ? [] : ['langs' => [$draft->language]]),
        ]);

        return new PublishedReplyModel($uri, AtPostUri::webUrl($uri));
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::postOrNull($ownPost->postId) ?? '');
        if (null === $recordKey) {
            throw new SocialPostRefusedException('The address names no Bluesky post.');
        }

        $this->records->delete($ownPost->account, BlueskyRecords::POST_COLLECTION, $recordKey);
    }

    /** @return array{root: array{uri: string, cid: ?string}, parent: array{uri: string, cid: ?string}} */
    private static function replyRefsOf(SocialPostModel $target): array
    {
        $parent = ['uri' => $target->postId, 'cid' => $target->contentId];
        $root = $target->replyContext->threadRoot;

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

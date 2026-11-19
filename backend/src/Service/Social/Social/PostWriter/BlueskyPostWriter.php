<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\Social\BlueskyFacets;
use App\Service\Social\Social\BlueskyExternalEmbeds;
use App\Service\Social\Social\BlueskyPostKeys;
use App\Service\Social\Social\BlueskyRecords;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Model\BlueskyPostKeyModel;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\BlueskyRecordUri;
use App\Service\Social\Social\Support\BlueskyStrongRef;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostWriter implements PostWriterInterface
{
    public const int MAX_GRAPHEMES = 300;
    private const string QUOTE_EMBED = 'app.bsky.embed.record';

    public function __construct(
        private BlueskyRecords $records,
        private BlueskyFacets $facets,
        private BlueskyExternalEmbeds $externalEmbeds,
        private BlueskyPostKeys $postKeys,
    ) {
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, static fn (): array => [
            'reply' => self::replyRefsOf($target),
        ]);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($target->account, $draft, static fn (): array => ['embed' => [
            '$type' => self::QUOTE_EMBED,
            'record' => BlueskyStrongRef::of($target),
        ]]);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        return $this->published($account, $draft, fn (): array => [
            'embed' => $this->externalEmbeds->embedOf($account, $url),
        ]);
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        return $this->records->delete($account, BlueskyRecordUri::POST, $postId);
    }

    /**
     * Built only when a post is created, so a resend that finds its post uploads no thumbnail.
     *
     * @param \Closure(): array<string, mixed> $attachment
     */
    private function published(SocialAccount $account, PostDraftModel $draft, \Closure $attachment): PublishedPostModel
    {
        self::assertFits($draft);

        $fields = fn (): array => [
            'text' => $draft->text,
            ...$attachment(),
            ...$this->facetsOf($draft->text),
            ...(null === $draft->language ? [] : ['langs' => [$draft->language]]),
        ];
        $uri = null === $draft->idempotencyKey
            ? $this->records->createPost($account, $fields(), null)
            : $this->publishedOnce($account, $fields, $this->postKeys->recordKeyFor($account, $draft->idempotencyKey));

        return new PublishedPostModel($uri, AtPostUri::webUrl($uri));
    }

    /**
     * The reference PDS answers a create on a record key already taken with a bare 500, so a failed create is looked
     * up before it counts as failed.
     *
     * @param \Closure(): array<string, mixed> $fields
     */
    private function publishedOnce(SocialAccount $account, \Closure $fields, BlueskyPostKeyModel $postKey): string
    {
        $existing = $postKey->seenBefore ? $this->records->findPost($account, $postKey->recordKey) : null;
        if (null !== $existing) {
            return $existing;
        }

        try {
            return $this->records->createPost($account, $fields(), $postKey->recordKey);
        } catch (SocialNetworkUnreachableException $exception) {
            try {
                $existing = $this->records->findPost($account, $postKey->recordKey);
            } catch (SocialNetworkUnreachableException) {
                $existing = null;
            }

            return $existing ?? throw $exception;
        }
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

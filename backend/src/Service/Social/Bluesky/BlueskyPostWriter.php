<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskyExternalEmbeds;
use App\Service\Social\Bluesky\BlueskyFacets;
use App\Service\Social\Bluesky\BlueskyPostKeys;
use App\Service\Social\Bluesky\BlueskyRecords;
use App\Service\Social\Bluesky\Model\BlueskyPostKeyModel;
use App\Service\Social\Bluesky\Support\BlueskyRecordUri;
use App\Service\Social\Bluesky\Support\BlueskyStrongRef;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\PostEmbedModelInterface;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\PostWriter\PostWriterInterface;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostDeleter\PostDeleterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostWriter implements PostWriterInterface, PostDeleterInterface
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

    public function publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel
    {
        $draft = $publication->draft;
        self::assertFits($draft);

        // Built only when a post is created, so a resend that finds its post uploads no thumbnail.
        $fields = fn (): array => [
            'text' => $draft->text,
            ...self::buildReplyField($publication->inReplyTo),
            ...$this->buildEmbedField($account, $publication->embed),
            ...$this->buildFacetsField($draft->text),
            ...(null === $draft->language ? [] : ['langs' => [$draft->language]]),
        ];
        $uri = null === $draft->idempotencyKey
            ? $this->records->createPost($account, $fields(), null)
            : $this->publishedOnce(
                $account,
                $fields,
                $this->postKeys->ensureRecordKey($account, $draft->idempotencyKey),
            );

        return new PublishedPostModel($uri, AtPostUri::webUrl($uri));
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        return $this->records->delete($account, BlueskyRecordUri::POST, $postId);
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

    /**
     * @return array{reply?: array{
     *     root: array{uri: string, cid: ?string},
     *     parent: array{uri: string, cid: ?string},
     * }}
     */
    private static function buildReplyField(?SocialPostModel $target): array
    {
        if (null === $target) {
            return [];
        }
        $parent = BlueskyStrongRef::fromPost($target);
        $root = $target->postContext->threadRoot;

        return ['reply' => [
            'root' => null === $root ? $parent : ['uri' => $root->uri, 'cid' => $root->contentId],
            'parent' => $parent,
        ]];
    }

    /** @return array{embed?: array<string, mixed>} */
    private function buildEmbedField(SocialAccount $account, ?PostEmbedModelInterface $embed): array
    {
        return match (true) {
            $embed instanceof QuotedPostEmbedModel => ['embed' => [
                '$type' => self::QUOTE_EMBED,
                'record' => BlueskyStrongRef::fromPost($embed->post),
            ]],
            $embed instanceof LinkEmbedModel => ['embed' => $this->externalEmbeds->prepareEmbed($account, $embed->url)],
            default => [],
        };
    }

    /** @return array{facets?: non-empty-list<mixed>} */
    private function buildFacetsField(string $text): array
    {
        $facets = $this->facets->buildFacets($text);

        return [] === $facets ? [] : ['facets' => $facets];
    }
}
